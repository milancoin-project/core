<?php
/**
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Sharing\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete all storage entries that have no matching entries in the shares_external table.
 */
class DeleteOrphanedStorages extends Command {

	/**
	 * @var IDBConnection
	 */
	protected $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('sharing:cleanup-remote-storages')
			->setDescription('Delete all storage entries that have no matching entries in the shares_external table');
	}

	public function execute(InputInterface $input, OutputInterface $output) {

		$remoteStorages = $this->getRemoteStorages();

		$output->writeln(count($remoteStorages) . " remote storage(s) need(s) to be checked");

		$remoteShareIds = $this->getRemoteShareIds();

		$output->writeln(count($remoteShareIds) . " remote share(s) exist");

		foreach ($remoteShareIds as $id => $remoteShareId) {
			if (isset($remoteStorages[$remoteShareId])) {
				$output->writeln("$remoteShareId belongs to remote share $id");
				unset($remoteStorages[$remoteShareId]);
			} else {
				$output->writeln("$remoteShareId for share $id has no matching storage, yet");
			}
		}

		foreach ($remoteStorages as $id => $numericId) {
			$output->writeln("$id [$numericId] can be deleted");
		}

	}

	public function getRemoteStorages() {

		$queryBuilder = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$queryBuilder->select(['id', 'numeric_id'])
			->from('storages')
			->where($queryBuilder->expr()->like(
				'id',
				$queryBuilder->createNamedParameter('shared::________________________________', IQueryBuilder::PARAM_STR),
				IQueryBuilder::PARAM_STR)
			)
			->andWhere($queryBuilder->expr()->notLike(
				'id',
				$queryBuilder->createNamedParameter('shared::/%', IQueryBuilder::PARAM_STR),
				IQueryBuilder::PARAM_STR)
			);
		$query = $queryBuilder->execute();

		$remoteStorages = [];

		while ($row = $query->fetch()) {
			$remoteStorages[$row['id']] = $row['numeric_id'];
		}

		return $remoteStorages;
	}

	public function getRemoteShareIds() {

		$queryBuilder = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$queryBuilder->select(['id', 'share_token', 'remote'])
			->from('share_external');
		$query = $queryBuilder->execute();

		$remoteShareIds = [];

		while ($row = $query->fetch()) {
			$remoteShareIds[$row['id']] = 'shared::' . md5($row['share_token'] . '@' . $row['remote']);
		}

		return $remoteShareIds;
	}
}