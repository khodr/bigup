<?php

namespace Spip\Bigup;

/**
 * Mappage entre Bigup et Flow
 *
 * @plugin     Bigup
 * @copyright  2015
 * @author     marcimat
 * @licence    GNU/GPL
 * @package    SPIP\Bigup\Fonctions
 */

include_spip('inc/Bigup/LogTrait');
include_spip('inc/Bigup/Flow');

/**
 * Gère la validité des requêtes et appelle Flow
**/
class Bigup {

	use LogTrait;

	/**
	 * Login ou identifiant de l'auteur qui intéragit
	 * @var string */
	private $auteur = '';

	/**
	 * Nom du formulaire qui utilise flow
	 * @var string */
	private $formulaire = '';

	/**
	 * Hash des arguments du formulaire
	 * @var string */
	private $formulaire_args = '';

	/**
	 * Identifie un formulaire par rapport à un autre identique sur la même page ayant un appel différent.
	 * @var string */
	private $formulaire_identifiant = '';

	/**
	 * Nom du champ dans le formulaire qui utilise flow
	 * @var string */
	private $champ = '';

	/**
	 * Token de la forme `champ:time:cle`
	 * @var string
	**/
	private $token = '';
	
	/**
	 * Expiration du token (en secondes)
	 *
	 * @todo À définir en configuration
	 * @var int
	**/
	private $token_expiration = 3600 * 24;

	/**
	 * Nom du répertoire, dans _DIR_TMP, qui va stocker les fichiers et morceaux de fichiers
	 * @var string */
	private $cache_dir = 'bigupload';

	/**
	 * Chemin du répertoire stockant les morceaux de fichiers
	 * @var string */
	 private $dir_parts = '';

	/**
	 * Chemin du répertoire stockant les fichiers terminés
	 * @var string */
	 private $dir_final = '';


	/**
	 * Constructeur
	 *
	 * @param string $formulaire Nom du formulaire
	 * @param string $formulaire_args Hash du formulaire
	 * @param string $token Jeton d'autorisation
	**/
	public function __construct($formulaire = '', $formulaire_args = '', $token = '') {
		$this->token = $token;
		$this->formulaire = $formulaire;
		$this->formulaire_args = $formulaire_args;
		$this->identifier_auteur();
		$this->identifier_formulaire();
	}

	/**
	 * Retrouve les paramètres pertinents pour gérer le test ou la réception de fichiers.
	**/
	public function recuperer_parametres() {
		$this->token           = _request('bigup_token');
		$this->formulaire      = _request('formulaire_action');
		$this->formulaire_args = _request('formulaire_action_args');
		$this->identifier_formulaire();
	}

	/**
	 * Répondre
	 *
	 * Envoie un statut HTTP de réponse et quitte, en fonction de ce qui était demandé,
	 * soit tester un morceau de fichier, soit réceptionner un morceau de fichier.
	 *
	 * Si les hash ne correspondaient pas, le programme quitte évidemment.
	**/
	public function repondre() {
		if (!$this->verifier_token()) {
			return $this->send(415);
		}

		$this->calculer_chemin_repertoires();

		include_spip('inc/Bigup/Flow');
		$flow = new Flow();
		$flow->definir_repertoire('parts', $this->dir_parts);
		$flow->definir_repertoire('final', $this->dir_final);
		$res = $flow->run();

		if (is_string($res)) {
			// remettre le fichier dans $FILES
			# $this->integrer_fichier($this->champ, $res);

			// on demande à nettoyer le répertoire des fichiers dans la foulée
			job_queue_add(
				'bigup_nettoyer_repertoire_upload',
				'Nettoyer répertoires et fichiers de Big Upload',
				array(0),
				'genie/'
			);
			$this->send(200);
		}

		if (is_int($res)) {
			$this->send($res);
		}

		$this->send(415);
	}


	/**
	 * Retrouve les fichiers qui ont été téléchargés et sont en attente pour ce formulaire
	 * et les réaffecte à $_FILES au passage.
	 *
	 * @return array
	**/
	public function reinserer_fichiers() {
		$this->calculer_chemin_repertoires();
		$liste = $this->trouver_fichiers_complets();

		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $description) {
				// TODO: Bug ici, si 2 fichiers sur 1 seul champ (input file multiple).
				// découvrir comment gère html/php d'habitude pour ce cas.
				$this->integrer_fichier($champ, $description);
			}
		}

		return $liste;
	}


	/**
	 * Enlève un fichier complet dont le hash est indiqué
	 *
	 * @param string $identifiant
	 *     Un identifiant du fichier
	 * @return bool True si le fichier est trouvé (et donc enlevé)
	**/
	public function enlever_fichier($identifiant) {
		$this->calculer_chemin_repertoires();
		$liste = $this->trouver_fichiers_complets();
		$this->debug("Demande de suppression du fichier $identifiant");

		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $k => $description) {
				if ($description['identifiant'] == $identifiant) {
					@unlink($description['pathname']);
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Retourne la liste des fichiers complets, classés par champ
	 *
	 * @return array Liste [ champ => [ chemin ]]
	**/
	public function trouver_fichiers_complets() {
		// la théorie veut ce rangement :
		// $dir/{champ}/{identifiant_fichier}/{nom du fichier.extension}
		$directory = $this->dir_final;

		// pas de répertoire… pas de fichier… simple comme bonjour :)
		if (!is_dir($directory)) {
			return [];
		}

		$liste = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory)
		);

		foreach ($files as $filename) {
			if ($filename->isDir()) continue; // . ..
			if ($filename->getFilename()[0] == '.') continue; // .ok

			$chemin = $filename->getPathname();
			$champ  = basename(dirname(dirname($chemin)));

			$liste[$champ][] = $this->decrire_fichier($chemin);
			$this->debug("Fichier retrouvé : $chemin");
		}

		return $liste;
	}


	/**
	 * Vérifier le token utilisé
	 *
	 * Le token doit arriver, de la forme `champ:time:clé`
	 * De même que formulaire_action et formulaire_action_args
	 *
	 * Le temps ne doit pas être trop vieux d'une part,
	 * et la clé de sécurité doit évidemment être valide.
	 * 
	 * @return bool
	**/
	public function verifier_token() {
		if (!$this->token) {
			$this->debug("Aucun token");
			return false;
		}

		$_token = explode(':', $this->token);

		if (count($_token) != 3) {
			$this->debug("Token mal formé");
			return false;
		}

		list($champ, $time, $cle) = $_token;
		$time = intval($time);
		$now = time();


		if (($now - $time) > $this->token_expiration) {
			$this->log("Token expiré");
			return false;
		}

		if (!$this->formulaire) {
			$this->log("Vérifier token : nom du formulaire absent");
			return false;
		}

		if (!$this->formulaire_args) {
			$this->log("Vérifier token : hash du formulaire absent");
			return false;
		}

		include_spip('inc/securiser_action');
		if (!verifier_action_auteur("bigup/$this->formulaire/$this->formulaire_args/$champ/$time", $cle)) {
			$this->error("Token invalide");
			return false;
		}

		$this->champ = $champ;

		$this->debug("Token OK : formulaire $this->formulaire, champ $champ, identifiant $this->formulaire_identifiant");

		return true;
	}


	/**
	 * Calcule les chemins des répertoires de travail
	 * qui stockent les morceaux de fichiers et les fichiers complétés
	**/
	public function calculer_chemin_repertoires() {
		$this->dir_parts = $this->calculer_chemin_repertoire('parts');
		$this->dir_final = $this->calculer_chemin_repertoire('final');
	}

	/**
	 * Calcule un chemin de répertoire de travail d'un type donné
	 * @return string
	**/
	public function calculer_chemin_repertoire($type) {
		return
			_DIR_TMP . $this->cache_dir
			. DIRECTORY_SEPARATOR . $type
			. DIRECTORY_SEPARATOR . $this->auteur
			. DIRECTORY_SEPARATOR . $this->formulaire
			. DIRECTORY_SEPARATOR . $this->formulaire_identifiant
			. DIRECTORY_SEPARATOR . $this->champ;
	}

	/**
	 * Identifier l'auteur qui accède
	 *
	 * @todo
	 *     Gérer le cas des auteurs anonymes, peut être avec l'identifiant de session php.
	 *
	 * @return string
	**/
	public function identifier_auteur() {
		// un nom d'identifiant humain si possible
		include_spip('inc/session');
		if (!$identifiant = session_get('login')) {
			$identifiant = session_get('id_auteur');
		}
		return $this->auteur = $identifiant;
	}

	/**
	 * Calcule un identifiant de formulaire en fonction de son hash
	 *
	 * @return string l'identifiant
	**/
	public function identifier_formulaire() {
		return $this->formulaire_identifiant = substr($this->formulaire_args, 0, 6);
	}

	/**
	 * Intégrer le fichier indiqué dans `$FILES`
	 *
	 * @param string $key
	 *     Clé d'enregistrement
	 * @param string|array $description
	 *     array : Description déjà calculée
	 *     string : chemin du fichier
	 * @return array
	 *     Description du fichier
	**/
	public function integrer_fichier($key, $description) {
		if (!is_array($description)) {
			$description = $this->decrire_fichier($description); 
		}
		return $_FILES[$key] = $description;
	}

	/**
	 * Décrire un fichier (comme dans `$_FILES`)
	 *
	 * @param string $chemin
	 * @return array
	**/
	public function decrire_fichier($chemin) {
		$filename = basename($chemin);
		$extension = pathinfo($chemin, PATHINFO_EXTENSION);
		include_spip('action/ajouter_documents');
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$desc = [
			'name' => $filename,
			'pathname' => $chemin, // celui là n'y est pas normalement dans $_FILES
			'identifiant' => md5($chemin), // celui là n'y est pas normalement dans $_FILES
			'extension' => corriger_extension(strtolower($extension)), // celui là n'y est pas normalement dans $_FILES
			'tmp_name' => $chemin,
			'size' => filesize($chemin),
			'type' => finfo_file($finfo, $chemin),
		];
		return $desc;
	}


	/**
	 * Envoie le code header indiqué… et arrête tout.
	 *
	 * @param int $code
	 * @return void
	**/
	public function send($code) {
		$this->debug("> send $code");
		http_response_code($code);
		exit;
	}


}