<?php
/**
 * This file is part of the SVN-Buddy library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy
 */

namespace ConsoleHelpers\SVNBuddy\Command;


use ConsoleHelpers\SVNBuddy\Config\AbstractConfigSetting;
use ConsoleHelpers\SVNBuddy\Config\IntegerConfigSetting;
use ConsoleHelpers\SVNBuddy\Config\RegExpsConfigSetting;
use ConsoleHelpers\ConsoleKit\Exception\CommandException;
use ConsoleHelpers\SVNBuddy\Repository\Parser\RevisionListParser;
use ConsoleHelpers\SVNBuddy\Repository\RevisionLog\RevisionLog;
use ConsoleHelpers\SVNBuddy\Repository\RevisionLog\RevisionPrinter;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LogCommand extends AbstractCommand implements IAggregatorAwareCommand, IConfigAwareCommand
{

	const SETTING_LOG_LIMIT = 'log.limit';

	const SETTING_LOG_MESSAGE_LIMIT = 'log.message-limit';

	const SETTING_LOG_MERGE_CONFLICT_REGEXPS = 'log.merge-conflict-regexps';

	/**
	 * Revision list parser.
	 *
	 * @var RevisionListParser
	 */
	private $_revisionListParser;

	/**
	 * Revision log
	 *
	 * @var RevisionLog
	 */
	private $_revisionLog;

	/**
	 * Revision printer.
	 *
	 * @var RevisionPrinter
	 */
	private $_revisionPrinter;

	/**
	 * Prepare dependencies.
	 *
	 * @return void
	 */
	protected function prepareDependencies()
	{
		parent::prepareDependencies();

		$container = $this->getContainer();

		$this->_revisionListParser = $container['revision_list_parser'];
		$this->_revisionPrinter = $container['revision_printer'];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
		$this->pathAcceptsUrl = true;

		$this
			->setName('log')
			->setDescription(
				'Show the log messages for a set of revisions, bugs, paths, refs, etc.'
			)
			->addArgument(
				'path',
				InputArgument::OPTIONAL,
				'Working copy path or URL',
				'.'
			)
			->addOption(
				'revisions',
				'r',
				InputOption::VALUE_REQUIRED,
				'List of revision(-s) and/or revision range(-s), e.g. <comment>53324</comment>, <comment>1224-4433</comment>'
			)
			->addOption(
				'bugs',
				'b',
				InputOption::VALUE_REQUIRED,
				'List of bug(-s), e.g. <comment>JRA-1234</comment>, <comment>43644</comment>'
			)
			->addOption(
				'refs',
				null,
				InputOption::VALUE_REQUIRED,
				'List of refs, e.g. <comment>trunk</comment>, <comment>branches/branch-name</comment>, <comment>tags/tag-name</comment>'
			)
			->addOption(
				'merges',
				null,
				InputOption::VALUE_NONE,
				'Show merge revisions only'
			)
			->addOption(
				'no-merges',
				null,
				InputOption::VALUE_NONE,
				'Hide merge revisions'
			)
			->addOption(
				'merged',
				null,
				InputOption::VALUE_NONE,
				'Shows only revisions, that were merged at least once'
			)
			->addOption(
				'not-merged',
				null,
				InputOption::VALUE_NONE,
				'Shows only revisions, that were not merged'
			)
			->addOption(
				'merged-by',
				null,
				InputOption::VALUE_REQUIRED,
				'Show revisions merged by list of revision(-s) and/or revision range(-s)'
			)
			->addOption(
				'with-details',
				'd',
				InputOption::VALUE_NONE,
				'Shows detailed revision information, e.g. paths affected'
			)
			->addOption(
				'with-summary',
				's',
				InputOption::VALUE_NONE,
				'Shows number of added/changed/removed paths in the revision'
			)
			->addOption(
				'with-refs',
				null,
				InputOption::VALUE_NONE,
				'Shows revision refs'
			)
			->addOption(
				'with-merge-oracle',
				null,
				InputOption::VALUE_NONE,
				'Shows number of paths in the revision, that can cause conflict upon merging'
			)
			->addOption(
				'with-merge-status',
				null,
				InputOption::VALUE_NONE,
				'Shows merge revisions affecting this revision'
			)
			->addOption(
				'max-count',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit the number of revisions to output'
			);

		parent::configure();
	}

	/**
	 * Return possible values for the named option
	 *
	 * @param string            $optionName Option name.
	 * @param CompletionContext $context    Completion context.
	 *
	 * @return array
	 */
	public function completeOptionValues($optionName, CompletionContext $context)
	{
		$ret = parent::completeOptionValues($optionName, $context);

		if ( $optionName === 'refs' ) {
			return $this->getAllRefs();
		}

		return $ret;
	}

	/**
	 * {@inheritdoc}
	 */
	public function initialize(InputInterface $input, OutputInterface $output)
	{
		parent::initialize($input, $output);

		$this->_revisionLog = $this->getRevisionLog($this->getWorkingCopyUrl());
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When both "--bugs" and "--revisions" options were specified.
	 * @throws CommandException When specified revisions are not present in current project.
	 * @throws CommandException When project contains no associated revisions.
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$bugs = $this->getList($this->io->getOption('bugs'));
		$revisions = $this->getList($this->io->getOption('revisions'));

		if ( $bugs && $revisions ) {
			throw new \RuntimeException('The "--bugs" and "--revisions" options are mutually exclusive.');
		}

		$missing_revisions = array();
		$revisions_by_path = $this->getRevisionsByPath();

		if ( $revisions ) {
			$revisions = $this->_revisionListParser->expandRanges($revisions);
			$revisions_by_path = array_intersect($revisions_by_path, $revisions);
			$missing_revisions = array_diff($revisions, $revisions_by_path);
		}
		elseif ( $bugs ) {
			// Only show bug-related revisions on given path. The $missing_revisions is always empty.
			$revisions_from_bugs = $this->_revisionLog->find('bugs', $bugs);
			$revisions_by_path = array_intersect($revisions_by_path, $revisions_from_bugs);
		}

		$merged_by = $this->getList($this->io->getOption('merged-by'));

		if ( $merged_by ) {
			$merged_by = $this->_revisionListParser->expandRanges($merged_by);
			$revisions_by_path = $this->_revisionLog->find('merges', $merged_by);
		}

		if ( $this->io->getOption('merges') ) {
			$revisions_by_path = array_intersect($revisions_by_path, $this->_revisionLog->find('merges', 'all_merges'));
		}
		elseif ( $this->io->getOption('no-merges') ) {
			$revisions_by_path = array_diff($revisions_by_path, $this->_revisionLog->find('merges', 'all_merges'));
		}

		if ( $this->io->getOption('merged') ) {
			$revisions_by_path = array_intersect($revisions_by_path, $this->_revisionLog->find('merges', 'all_merged'));
		}
		elseif ( $this->io->getOption('not-merged') ) {
			$revisions_by_path = array_diff($revisions_by_path, $this->_revisionLog->find('merges', 'all_merged'));
		}

		if ( $missing_revisions ) {
			throw new CommandException($this->getMissingRevisionsErrorMessage($missing_revisions));
		}
		elseif ( !$revisions_by_path ) {
			throw new CommandException('No matching revisions found.');
		}

		rsort($revisions_by_path, SORT_NUMERIC);

		if ( $bugs || $revisions ) {
			// Don't limit revisions, when provided explicitly by user.
			$revisions_by_path_with_limit = $revisions_by_path;
		}
		else {
			// Apply limit only, when no explicit bugs/revisions are set.
			$revisions_by_path_with_limit = array_slice($revisions_by_path, 0, $this->getMaxCount());
		}

		$revisions_by_path_count = count($revisions_by_path);
		$revisions_by_path_with_limit_count = count($revisions_by_path_with_limit);

		if ( $revisions_by_path_with_limit_count === $revisions_by_path_count ) {
			$this->io->writeln(sprintf(
				' * Showing <info>%d</info> revision(-s) in %s:',
				$revisions_by_path_with_limit_count,
				$this->getRevisionLogIdentifier()
			));
		}
		else {
			$this->io->writeln(sprintf(
				' * Showing <info>%d</info> of <info>%d</info> revision(-s) in %s:',
				$revisions_by_path_with_limit_count,
				$revisions_by_path_count,
				$this->getRevisionLogIdentifier()
			));
		}

		$this->printRevisions($revisions_by_path_with_limit);
	}

	/**
	 * Returns revision log identifier.
	 *
	 * @return string
	 */
	protected function getRevisionLogIdentifier()
	{
		$ret = '<info>' . $this->_revisionLog->getProjectPath() . '</info> project';

		$ref_name = $this->_revisionLog->getRefName();

		if ( $ref_name ) {
			$ret .= ' (ref: <info>' . $ref_name . '</info>)';
		}
		else {
			$ret .= ' (all refs)';
		}

		return $ret;
	}

	/**
	 * Shows error about missing revisions.
	 *
	 * @param array $missing_revisions Missing revisions.
	 *
	 * @return string
	 */
	protected function getMissingRevisionsErrorMessage(array $missing_revisions)
	{
		$refs = $this->io->getOption('refs');
		$missing_revisions = implode(', ', $missing_revisions);

		if ( $refs ) {
			$revision_source = 'in "' . $refs . '" ref(-s)';
		}
		else {
			$revision_source = 'at "' . $this->getWorkingCopyUrl() . '" url';
		}

		return 'The ' . $missing_revisions . ' revision(-s) not found ' . $revision_source . '.';
	}

	/**
	 * Returns list of revisions by path.
	 *
	 * @return array
	 * @throws CommandException When given refs doesn't exist.
	 */
	protected function getRevisionsByPath()
	{
		$refs = $this->getList($this->io->getOption('refs'));
		$relative_path = $this->repositoryConnector->getRelativePath($this->getWorkingCopyPath()) . '/';

		if ( !$refs ) {
			$ref = $this->repositoryConnector->getRefByPath($relative_path);

			// Use search by ref, when working copy represents ref root folder.
			if ( $ref !== false && preg_match('#' . preg_quote($ref, '#') . '/$#', $relative_path) ) {
				return $this->_revisionLog->find('refs', $ref);
			}
		}

		if ( $refs ) {
			$incorrect_refs = array_diff($refs, $this->getAllRefs());

			if ( $incorrect_refs ) {
				throw new CommandException(
					'The following refs are unknown: "' . implode('", "', $incorrect_refs) . '".'
				);
			}

			return $this->_revisionLog->find('refs', $refs);
		}

		return $this->_revisionLog->find('paths', $relative_path);
	}

	/**
	 * Returns displayed revision limit.
	 *
	 * @return integer
	 */
	protected function getMaxCount()
	{
		$max_count = $this->io->getOption('max-count');

		if ( $max_count !== null ) {
			return $max_count;
		}

		return $this->getSetting(self::SETTING_LOG_LIMIT);
	}

	/**
	 * Prints revisions.
	 *
	 * @param array $revisions Revisions.
	 *
	 * @return void
	 */
	protected function printRevisions(array $revisions)
	{
		$column_mapping = array(
			'with-details' => RevisionPrinter::COLUMN_DETAILS,
			'with-summary' => RevisionPrinter::COLUMN_SUMMARY,
			'with-refs' => RevisionPrinter::COLUMN_REFS,
			'with-merge-oracle' => RevisionPrinter::COLUMN_MERGE_ORACLE,
			'with-merge-status' => RevisionPrinter::COLUMN_MERGE_STATUS,
		);

		foreach ( $column_mapping as $option_name => $column ) {
			if ( $this->io->getOption($option_name) ) {
				$this->_revisionPrinter->withColumn($column);
			}
		}

		$this->_revisionPrinter->setMergeConflictRegExps($this->getSetting(self::SETTING_LOG_MERGE_CONFLICT_REGEXPS));
		$this->_revisionPrinter->setLogMessageLimit($this->getSetting(self::SETTING_LOG_MESSAGE_LIMIT));

		$this->_revisionPrinter->printRevisions($this->_revisionLog, $revisions, $this->io->getOutput());
	}

	/**
	 * Returns list of config settings.
	 *
	 * @return AbstractConfigSetting[]
	 */
	public function getConfigSettings()
	{
		return array(
			new IntegerConfigSetting(self::SETTING_LOG_LIMIT, 10),
			new IntegerConfigSetting(self::SETTING_LOG_MESSAGE_LIMIT, 68),
			new RegExpsConfigSetting(self::SETTING_LOG_MERGE_CONFLICT_REGEXPS, '#/composer\.lock$#'),
		);
	}

}
