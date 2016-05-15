<?php
/**
 * This file is part of the SVN-Buddy library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy
 */

namespace ConsoleHelpers\SVNBuddy\Repository\RevisionLog\Plugin;


use Aura\Sql\ExtendedPdoInterface;
use ConsoleHelpers\SVNBuddy\Database\DatabaseCache;
use ConsoleHelpers\SVNBuddy\Repository\Connector\Connector;
use ConsoleHelpers\SVNBuddy\Repository\RevisionLog\PathCollisionDetector;
use ConsoleHelpers\SVNBuddy\Repository\RevisionLog\RepositoryFiller;
use ConsoleHelpers\SVNBuddy\Repository\RevisionLog\RevisionLog;

class PathsPlugin extends AbstractRepositoryCollectorPlugin
{

	const TYPE_NEW = 'new';

	const TYPE_EXISTING = 'existing';

	const STATISTIC_PATH_ADDED = 'path_added';

	const STATISTIC_PATH_FOUND = 'path_found';

	const STATISTIC_PROJECT_ADDED = 'project_added';

	const STATISTIC_PROJECT_FOUND = 'project_found';

	const STATISTIC_PROJECT_COLLISION_FOUND = 'project_collision_found';

	const STATISTIC_REF_ADDED = 'ref_added';

	const STATISTIC_REF_FOUND = 'ref_found';

	const STATISTIC_COMMIT_ADDED_TO_PROJECT = 'commit_added_to_project';

	const STATISTIC_COMMIT_ADDED_TO_REF = 'commit_added_to_ref';

	const STATISTIC_EMPTY_COMMIT = 'empty_commit';

	/**
	 * Database cache.
	 *
	 * @var DatabaseCache
	 */
	private $_databaseCache;

	/**
	 * Projects.
	 *
	 * @var array
	 */
	private $_projects = array(
		self::TYPE_NEW => array(),
		self::TYPE_EXISTING => array(),
	);

	/**
	 * Refs.
	 *
	 * @var array
	 */
	private $_refs = array();

	/**
	 * Repository connector.
	 *
	 * @var Connector
	 */
	private $_repositoryConnector;

	/**
	 * Path collision detector.
	 *
	 * @var PathCollisionDetector
	 */
	private $_pathCollisionDetector;

	/**
	 * Create paths revision log plugin.
	 *
	 * @param ExtendedPdoInterface  $database                Database.
	 * @param RepositoryFiller      $repository_filler       Repository filler.
	 * @param DatabaseCache         $database_cache          Database cache.
	 * @param Connector             $repository_connector    Repository connector.
	 * @param PathCollisionDetector $path_collision_detector Path collision detector.
	 */
	public function __construct(
		ExtendedPdoInterface $database,
		RepositoryFiller $repository_filler,
		DatabaseCache $database_cache,
		Connector $repository_connector,
		PathCollisionDetector $path_collision_detector
	) {
		parent::__construct($database, $repository_filler);

		$this->_databaseCache = $database_cache;
		$this->_repositoryConnector = $repository_connector;
		$this->_pathCollisionDetector = $path_collision_detector;

		$this->initDatabaseCache();
	}

	/**
	 * Hook, that is called before "RevisionLog::refresh" method call.
	 *
	 * @return void
	 */
	public function whenDatabaseReady()
	{
		$sql = 'SELECT Path
				FROM Projects';
		$project_paths = $this->database->fetchCol($sql);

		$this->_pathCollisionDetector->addPaths($project_paths);
	}

	/**
	 * Initializes database cache.
	 *
	 * @return void
	 */
	protected function initDatabaseCache()
	{
		$this->_databaseCache->cacheTable('Projects');
		$this->_databaseCache->cacheTable('ProjectRefs');
		$this->_databaseCache->cacheTable('Paths');
	}

	/**
	 * Returns plugin name.
	 *
	 * @return string
	 */
	public function getName()
	{
		return 'paths';
	}

	/**
	 * Returns revision query flags.
	 *
	 * @return array
	 */
	public function getRevisionQueryFlags()
	{
		return array(RevisionLog::FLAG_VERBOSE);
	}

	/**
	 * Defines parsing statistic types.
	 *
	 * @return array
	 */
	public function defineStatisticTypes()
	{
		return array(
			self::STATISTIC_PATH_ADDED,
			self::STATISTIC_PATH_FOUND,
			self::STATISTIC_PROJECT_ADDED,
			self::STATISTIC_PROJECT_FOUND,
			self::STATISTIC_PROJECT_COLLISION_FOUND,
			self::STATISTIC_REF_ADDED,
			self::STATISTIC_REF_FOUND,
			self::STATISTIC_COMMIT_ADDED_TO_PROJECT,
			self::STATISTIC_COMMIT_ADDED_TO_REF,
			self::STATISTIC_EMPTY_COMMIT,
		);
	}

	/**
	 * Does actual parsing.
	 *
	 * @param integer           $revision  Revision.
	 * @param \SimpleXMLElement $log_entry Log Entry.
	 *
	 * @return void
	 */
	protected function doParse($revision, \SimpleXMLElement $log_entry)
	{
		// Reset cached info after previous revision processing.
		$this->_projects = array(
			self::TYPE_NEW => array(),
			self::TYPE_EXISTING => array(),
		);
		$this->_refs = array();

		// This is empty revision.
		if ( !isset($log_entry->paths) ) {
			$this->recordStatistic(self::STATISTIC_EMPTY_COMMIT);

			return;
		}

		foreach ( $this->sortPaths($log_entry->paths) as $path_node ) {
			$kind = (string)$path_node['kind'];
			$action = (string)$path_node['action'];
			$path = $this->adaptPathToKind((string)$path_node, $kind);

			if ( $path_node['copyfrom-rev'] !== null ) {
				$copy_revision = (int)$path_node['copyfrom-rev'];
				$copy_path = $this->adaptPathToKind((string)$path_node['copyfrom-path'], $kind);
				$copy_path_id = $this->processPath($copy_path, $copy_revision, '', false);
			}

			$this->repositoryFiller->addPathToCommit(
				$revision,
				$action,
				$kind,
				$this->processPath($path, $revision, $action),
				isset($copy_revision) ? $copy_revision : null,
				isset($copy_path_id) ? $copy_path_id : null
			);
		}

		foreach ( array_keys($this->_projects[self::TYPE_EXISTING]) as $project_id ) {
			$this->addCommitToProject($revision, $project_id);
		}

		foreach ( $this->_projects[self::TYPE_NEW] as $project_id => $project_path ) {
			$associated_revisions = $this->addMissingCommitsToProject($project_id, $project_path);

			if ( !in_array($revision, $associated_revisions) ) {
				$this->addCommitToProject($revision, $project_id);
			}
		}

		foreach ( array_keys($this->_refs) as $ref_id ) {
			$this->addCommitToRef($revision, $ref_id);
		}
	}

	/**
	 * Sorts paths to move parent folders above their sub-folders.
	 *
	 * @param \SimpleXMLElement $paths Paths.
	 *
	 * @return \SimpleXMLElement[]
	 */
	protected function sortPaths(\SimpleXMLElement $paths)
	{
		$sorted_paths = array();

		foreach ( $paths->path as $path_node ) {
			$sorted_paths[(string)$path_node] = $path_node;
		}

		ksort($sorted_paths, defined('SORT_NATURAL') ? SORT_NATURAL : SORT_STRING);

		return $sorted_paths;
	}

	/**
	 * Processes path.
	 *
	 * @param string  $path     Path.
	 * @param integer $revision Revision.
	 * @param string  $action   Action.
	 * @param boolean $is_usage This is usage.
	 *
	 * @return integer
	 */
	protected function processPath($path, $revision, $action, $is_usage = true)
	{
		$path_hash = $this->repositoryFiller->getPathChecksum($path);

		$sql = 'SELECT Id, ProjectPath, RefName, RevisionAdded, RevisionDeleted, RevisionLastSeen
				FROM Paths
				WHERE PathHash = :path_hash';
		$path_data = $this->_databaseCache->getFromCache(
			'Paths',
			$path_hash,
			$sql,
			array('path_hash' => $path_hash)
		);

		if ( $path_data !== false ) {
			if ( $action ) {
				$fields_hash = $this->repositoryFiller->getPathTouchFields($action, $revision, $path_data);

				if ( $fields_hash ) {
					$touched_paths = $this->repositoryFiller->touchPath($path, $revision, $fields_hash);

					foreach ( $touched_paths as $touched_path_hash => $touched_path_fields_hash ) {
						if ( $this->_databaseCache->getFromCache('Paths', $touched_path_hash) !== false ) {
							$this->_databaseCache->setIntoCache('Paths', $touched_path_hash, $touched_path_fields_hash);
						}
					}
				}
			}

			if ( $path_data['ProjectPath'] && $path_data['RefName'] ) {
				$project_id = $this->processProject($path_data['ProjectPath'], $is_usage);
				$this->processRef($project_id, $path_data['RefName'], $is_usage);
			}

			$this->recordStatistic(self::STATISTIC_PATH_FOUND);

			return $path_data['Id'];
		}

		$ref = $this->_repositoryConnector->getRefByPath($path);

		if ( $ref !== false ) {
			$project_path = substr($path, 0, strpos($path, $ref));

			if ( $this->_pathCollisionDetector->isCollision($project_path) ) {
				$project_path = $ref = '';
				$this->recordStatistic(self::STATISTIC_PROJECT_COLLISION_FOUND);
			}
		}
		else {
			$project_path = '';
		}

		$path_id = $this->repositoryFiller->addPath($path, (string)$ref, $project_path, $revision);
		$this->_databaseCache->setIntoCache('Paths', $path_hash, array(
			'Id' => $path_id,
			'ProjectPath' => $project_path,
			'RefName' => (string)$ref,
			'RevisionAdded' => $revision,
			'RevisionDeleted' => null,
			'RevisionLastSeen' => $revision,
		));

		if ( $project_path && $ref ) {
			$project_id = $this->processProject($project_path, $is_usage);
			$this->processRef($project_id, $ref, $is_usage);
		}

		$this->recordStatistic(self::STATISTIC_PATH_ADDED);

		return $path_id;
	}

	/**
	 * Adapts path to kind.
	 *
	 * @param string $path Path.
	 * @param string $kind Kind.
	 *
	 * @return string
	 */
	protected function adaptPathToKind($path, $kind)
	{
		if ( $kind === 'dir' ) {
			$path .= '/';
		}

		return $path;
	}

	/**
	 * Processes project.
	 *
	 * @param string  $project_path Project path.
	 * @param boolean $is_usage     This is usage.
	 *
	 * @return integer
	 */
	protected function processProject($project_path, $is_usage = true)
	{
		$sql = 'SELECT Id
				FROM Projects
				WHERE Path = :path';
		$project_data = $this->_databaseCache->getFromCache(
			'Projects',
			$project_path,
			$sql,
			array('path' => $project_path)
		);

		if ( $project_data !== false ) {
			$project_id = $project_data['Id'];

			// Don't consider project both new & existing (e.g. when single commit adds several branches).
			if ( $is_usage && !isset($this->_projects[self::TYPE_NEW][$project_id]) ) {
				$this->_projects[self::TYPE_EXISTING][$project_id] = $project_path;
				$this->recordStatistic(self::STATISTIC_PROJECT_FOUND);
			}

			return $project_id;
		}

		$project_id = $this->repositoryFiller->addProject($project_path);
		$this->_databaseCache->setIntoCache('Projects', $project_path, array('Id' => $project_id));
		$this->_pathCollisionDetector->addPaths(array($project_path));

		if ( $is_usage ) {
			$this->_projects[self::TYPE_NEW][$project_id] = $project_path;
			$this->recordStatistic(self::STATISTIC_PROJECT_ADDED);
		}

		return $project_id;
	}

	/**
	 * Processes ref.
	 *
	 * @param integer $project_id Project ID.
	 * @param string  $ref        Ref.
	 * @param boolean $is_usage   This is usage.
	 *
	 * @return integer
	 */
	protected function processRef($project_id, $ref, $is_usage = true)
	{
		$cache_key = $project_id . ':' . $ref;

		$sql = 'SELECT Id
				FROM ProjectRefs
				WHERE ProjectId = :project_id AND Name = :ref';
		$ref_data = $this->_databaseCache->getFromCache(
			'ProjectRefs',
			$cache_key,
			$sql,
			array('project_id' => $project_id, 'ref' => $ref)
		);

		if ( $ref_data !== false ) {
			$ref_id = $ref_data['Id'];

			if ( $is_usage ) {
				$this->_refs[$ref_id] = true;
			}

			$this->recordStatistic(self::STATISTIC_REF_FOUND);

			return $ref_id;
		}

		$ref_id = $this->repositoryFiller->addRefToProject($ref, $project_id);
		$this->_databaseCache->setIntoCache('ProjectRefs', $cache_key, array('Id' => $ref_id));

		if ( $is_usage ) {
			$this->_refs[$ref_id] = true;
		}

		$this->recordStatistic(self::STATISTIC_REF_ADDED);

		return $ref_id;
	}

	/**
	 * Retroactively map paths/commits to project, where path doesn't contain ref.
	 *
	 * @param integer $project_id   Project ID.
	 * @param string  $project_path Project path.
	 *
	 * @return array
	 */
	protected function addMissingCommitsToProject($project_id, $project_path)
	{
		$sql = "SELECT Id
				FROM Paths
				WHERE ProjectPath = '' AND Path LIKE :path_matcher";
		$paths_without_project = $this->database->fetchCol($sql, array('path_matcher' => $project_path . '%'));

		if ( !$paths_without_project ) {
			return array();
		}

		$this->repositoryFiller->movePathsIntoProject($paths_without_project, $project_path);

		$sql = 'SELECT Revision
				FROM CommitPaths
				WHERE PathId IN (:path_ids)';
		$commit_revisions = $this->database->fetchCol($sql, array('path_ids' => $paths_without_project));

		foreach ( array_unique($commit_revisions) as $commit_revision ) {
			$this->addCommitToProject($commit_revision, $project_id);
		}

		return $commit_revisions;
	}

	/**
	 * Associates revision with project.
	 *
	 * @param integer $revision   Revision.
	 * @param integer $project_id Project.
	 *
	 * @return void
	 */
	protected function addCommitToProject($revision, $project_id)
	{
		$this->repositoryFiller->addCommitToProject($revision, $project_id);
		$this->recordStatistic(self::STATISTIC_COMMIT_ADDED_TO_PROJECT);
	}

	/**
	 * Associates revision with ref.
	 *
	 * @param integer $revision Revision.
	 * @param integer $ref_id   Ref.
	 *
	 * @return void
	 */
	protected function addCommitToRef($revision, $ref_id)
	{
		$this->repositoryFiller->addCommitToRef($revision, $ref_id);
		$this->recordStatistic(self::STATISTIC_COMMIT_ADDED_TO_REF);
	}

	/**
	 * Find revisions by collected data.
	 *
	 * @param array       $criteria     Criteria.
	 * @param string|null $project_path Project path.
	 *
	 * @return array
	 */
	public function find(array $criteria, $project_path)
	{
		if ( !$criteria ) {
			return array();
		}

		$project_id = $this->getProject($project_path);

		if ( reset($criteria) === '' ) {
			// Include revisions from all paths.
			$sql = 'SELECT cpa.Revision
					FROM CommitPaths cpa
					JOIN CommitProjects cpr ON cpr.Revision = cpa.Revision
					WHERE cpr.ProjectId = :project_id';
			$path_revisions = array_flip($this->database->fetchCol($sql, array('project_id' => $project_id)));
		}
		else {
			// Include revisions from given sub-path only.
			$path_revisions = array();

			foreach ( $criteria as $criterion ) {
				if ( strpos($criterion, ':') !== false ) {
					list ($field, $value) = explode(':', $criterion, 2);

					if ( $field === 'action' ) {
						$sql = 'SELECT cpa.Revision
								FROM CommitPaths cpa
								JOIN CommitProjects cpr ON cpr.Revision = cpa.Revision
								WHERE cpr.ProjectId = :project_id AND cpa.Action LIKE :action';
						$tmp_revisions = $this->database->fetchCol($sql, array(
							'project_id' => $project_id,
							'action' => $value,
						));
					}
					elseif ( $field === 'kind' ) {
						$sql = 'SELECT cpa.Revision
								FROM CommitPaths cpa
								JOIN CommitProjects cpr ON cpr.Revision = cpa.Revision
								WHERE cpr.ProjectId = :project_id AND cpa.Kind LIKE :kind';
						$tmp_revisions = $this->database->fetchCol($sql, array(
							'project_id' => $project_id,
							'kind' => $value,
						));
					}
					else {
						$error_msg = 'Searching by "%s" is not supported by "%s" plugin.';
						throw new \InvalidArgumentException(sprintf($error_msg, $field, $this->getName()));
					}
				}
				else {
					$path = $criterion;

					if ( substr($path, -1, 1) === '/' ) {
						// Folder given > search also in it's sub-folders.
						$sql = 'SELECT cpa.Revision
							FROM CommitPaths cpa
							JOIN Paths p ON p.Id = cpa.PathId
							JOIN CommitProjects cpr ON cpr.Revision = cpa.Revision
							WHERE cpr.ProjectId = :project_id AND p.Path LIKE :path';
						$tmp_revisions = $this->database->fetchCol($sql, array(
							'project_id' => $project_id,
							'path' => $path . '%',
						));
					}
					else {
						// File given > search for that file specifically.
						$sql = 'SELECT cpa.Revision
							FROM CommitPaths cpa
							JOIN Paths p ON p.Id = cpa.PathId
							JOIN CommitProjects cpr ON cpr.Revision = cpa.Revision
							WHERE cpr.ProjectId = :project_id AND p.PathHash = :path_hash';
						$tmp_revisions = $this->database->fetchCol($sql, array(
							'project_id' => $project_id,
							'path_hash' => $this->repositoryFiller->getPathChecksum($path),
						));
					}
				}

				foreach ( $tmp_revisions as $revision ) {
					$path_revisions[$revision] = true;
				}
			}
		}

		$path_revisions = array_keys($path_revisions);
		sort($path_revisions, SORT_NUMERIC);

		return $path_revisions;
	}

	/**
	 * Returns information about revisions.
	 *
	 * @param array $revisions Revisions.
	 *
	 * @return array
	 */
	public function getRevisionsData(array $revisions)
	{
		$results = array();

		$sql = 'SELECT cp.Revision, p1.Path, cp.Kind, cp.Action, p2.Path AS CopyPath, cp.CopyRevision
				FROM CommitPaths cp
				JOIN Paths p1 ON p1.Id = cp.PathId
				LEFT JOIN Paths p2 ON p2.Id = cp.CopyPathId
				WHERE cp.Revision IN (:revision_ids)';
		$revisions_data = $this->database->fetchAll($sql, array('revision_ids' => $revisions));

		foreach ( $revisions_data as $revision_data ) {
			$revision = $revision_data['Revision'];

			if ( !isset($results[$revision]) ) {
				$results[$revision] = array();
			}

			$results[$revision][] = array(
				'path' => $revision_data['Path'],
				'kind' => $revision_data['Kind'],
				'action' => $revision_data['Action'],
				'copyfrom-path' => $revision_data['CopyPath'],
				'copyfrom-rev' => $revision_data['CopyRevision'],
			);
		}

		$this->assertNoMissingRevisions($revisions, $results);

		return $results;
	}

	/**
	 * Frees consumed memory.
	 *
	 * @return void
	 *
	 * @codeCoverageIgnore
	 */
	protected function freeMemoryManually()
	{
		parent::freeMemoryManually();

		$this->_databaseCache->clear();
	}

}
