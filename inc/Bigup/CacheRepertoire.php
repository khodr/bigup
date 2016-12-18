<?php

namespace Spip\Bigup;

/**
 * Gère le cache des fichiers dans tmp/bigupload
 *
 * @plugin     Bigup
 * @copyright  2016
 * @author     marcimat
 * @licence    GNU/GPL
 * @package    SPIP\Bigup\Fonctions
 */


/**
 * Gère le cache des fichiers dans tmp/bigupload
 **/
class CacheRepertoire {

	use LogTrait;

	/**
	 * Gestion générale du cache
	 * @var Cache
	 */
	private $cache = null;

	/**
	 * Chemin du répertoire temporaire pour ce formulaire
	 * @var string */
	private $dir = '';

	/**
	 *  Chemin du répertoire temporaire pour un champ de formulaire
	 * @var string */
	private $fichiers = null;

	/**
	 * Constructeur
	 * @param Identifier $identifier
	 * @param string $nom
	 *     Nom du répertoire de cache
	 */
	public function __construct(Cache $cache, $nom) {
		$this->cache = $cache;
		$this->dir =
			_DIR_TMP . $this->cache->cache_dir
			. DIRECTORY_SEPARATOR . $nom
			. DIRECTORY_SEPARATOR . $this->cache->identifier->auteur
			. DIRECTORY_SEPARATOR . $this->cache->identifier->formulaire
			. DIRECTORY_SEPARATOR . $this->cache->identifier->formulaire_identifiant;

		// Si le nom du champ est connu, on crée une facilité pour accéder au chemin des fichiers
		if ($this->cache->identifier->champ) {
			$this->fichiers = new CacheFichiers($this, $this->cache->identifier->champ);
		}
	}

	/**
	 * Pouvoir obtenir les propriétés privées sans les modifier.
	 * @param string $property
	 */
	public function __get($property) {
		if (property_exists($this, $property)) {
			return $this->$property;
		}
		$this->debug("Propriété `$property` demandée mais inexistante.");
		return null;
	}

	/**
	 * Retourne la liste des fichiers de ce cache,
	 * classés par champ
	 *
	 * @return array Liste [ champ => [ chemin ]]
	 **/
	public function trouver_fichiers() {
		// la théorie veut ce rangement :
		// $dir/{champ}/{identifiant_fichier}/{nom du fichier.extension}
		$directory = $this->dir;

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
			$champ = Cache::retrouver_champ_depuis_chemin($chemin);

			if (empty($liste[$champ])) {
				$liste[$champ] = [];
			}
			$liste[$champ][] = Cache::decrire_fichier($chemin);
			$this->debug("Fichier retrouvé : $chemin");
		}

		return $liste;
	}


	/**
	 * Pour un champ donné (attribut name) et une description
	 * de fichier issue des données de `$_FILES`, déplace le fichier
	 * dans le cache de bigup
	 *
	 * @param string $champ
	 * @param array $description
	 */
	public function stocker_fichier($champ, $description) {
		$nom = $description['name'];
		$chemin = $description['tmp_name'];
		$nouveau = new CacheFichiers($this, $champ);
		$nouveau_chemin = $nouveau->dir_fichier($description['size'] . $nom, $nom);

		if (GestionRepertoires::creer_sous_repertoire(dirname($nouveau_chemin))) {
			if (GestionRepertoires::deplacer_fichier_upload($chemin, $nouveau_chemin)) {
				return Cache::decrire_fichier($nouveau_chemin);
			}
		}

		return false;
	}



	/**
	 * Enlève un fichier
	 *
	 * @param string $identifiant_ou_repertoire
	 *     Identifiant du fichier, tel que créé avec CacheFichiers::hash_identifiant()
	 * @return bool
	 *     True si le fichier est trouvé (et donc enlevé)
	 **/
	public function supprimer_fichier($identifiant)
	{
		if (!$identifiant) {
			return false;
		}
		return $this->supprimer_fichiers([$identifiant]);
	}


	/**
	 * Enlève des fichiers dont les identifiants sont indiqués
	 *
	 * @param string|array $identifiants
	 *     Identifiant ou liste d'identifiants de fichier
	 **/
	public function supprimer_fichiers($identifiants) {
		$liste = $this->trouver_fichiers();
		if (!is_array($identifiants)) {
			$identifiants = [$identifiants];
		}
		$identifiants = array_filter($identifiants);

		$this->debug("Demande de suppression de fichiers : " . implode(', ', $identifiants));
		foreach ($liste as $champ => $fichiers) {
			foreach ($fichiers as $description) {
				if (in_array($description['identifiant'], $identifiants)) {
					GestionRepertoires::supprimer_repertoire(dirname($description['pathname']));
				}
			}
		}
	}


	/**
	 * Supprimer le répertoire indiqué et les répertoires parents éventuellement
	 *
	 * Si l'on indique une arborescence dans tmp/bigup/final/xxx, le répertoire
	 * correspondant dans tmp/bigup/parts/xxx sera également supprimé, et inversement.
	 *
	 * @param string $chemin
	 *     Chemin du répertoire stockant un fichier bigup
	 * @return bool
	 */
	function supprimer_repertoire() {
		GestionRepertoires::supprimer_repertoire($this->dir);
		return true;
	}



}