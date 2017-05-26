<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
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

namespace OCA\dav\Migrations;

use OCA\DAV\CalDAV\BirthdayService;
use OCP\IDBConnection;
use OCP\Migration\ISqlMigration;

/**
 * Fix the calendar components of the system contact birthday calendar
 */
class Version20170526100342 implements ISqlMigration {

	public function sql(IDBConnection $connection) {
		$query = $connection->getQueryBuilder();
		$updated = $query->update('calendars')
			->set('components', $query->createNamedParameter('VEVENT'))
			->set('calendarorder', $query->createNamedParameter('100'))
			->where($query->expr()->eq('uri', $query->createNamedParameter(BirthdayService::BIRTHDAY_CALENDAR_URI)))
			->execute();
	}
}
