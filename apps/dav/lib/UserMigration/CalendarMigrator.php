<?php

declare(strict_types=1);

/**
 * @copyright 2022 Christopher Ng <chrng8@gmail.com>
 *
 * @author Christopher Ng <chrng8@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DAV\UserMigration;

use function Safe\fopen;
use OC\Files\Filesystem;
use OC\Files\View;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\ICSExportPlugin\ICSExportPlugin;
use OCA\DAV\CalDAV\Plugin as CalDAVPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\Server as SabreDavServer;
use OCA\DAV\RootCollection;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Version as SabreDavVersion;
use Sabre\VObject\Component as VObjectComponent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader as VObjectReader;
use Sabre\VObject\UUIDUtil;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\OutputInterface;

class CalendarMigrator {

	private CalDavBackend $calDavBackend;

	private ICalendarManager $calendarManager;

	// ICSExportPlugin is injected to use the mergeObjects() method and is not to be used as a SabreDAV server plugin
	private ICSExportPlugin $icsExportPlugin;

	private Defaults $defaults;

	private LoggerInterface $logger;

	private IL10N $l10n;

	private SabreDavServer $sabreDavServer;

	public const USERS_URI_ROOT = 'principals/users/';

	public const FILENAME_EXT = '.ics';

	public function __construct(
		CalDavBackend $calDavBackend,
		ICalendarManager $calendarManager,
		ICSExportPlugin $icsExportPlugin,
		Defaults $defaults,
		LoggerInterface $logger,
		IL10N $l10n
	) {
		$this->calDavBackend = $calDavBackend;
		$this->calendarManager = $calendarManager;
		$this->icsExportPlugin = $icsExportPlugin;
		$this->defaults = $defaults;
		$this->logger = $logger;
		$this->l10n = $l10n;

		$root = new RootCollection();
		$this->sabreDavServer = new SabreDavServer(new CachingTree($root));
		$this->sabreDavServer->addPlugin(new CalDAVPlugin());
	}

	public function getPrincipalUri(IUser $user): string {
		return CalendarMigrator::USERS_URI_ROOT . $user->getUID();
	}

	/**
	 * @return array<int, array{name: string, data: string}>
	 *
	 * @throws CalendarMigratorException
	 */
	public function getCalendarExports(IUser $user): array {
		$principalUri = $this->getPrincipalUri($user);

		return array_values(array_filter(array_map(
			function (ICalendar $calendar) use ($user) {
				try {
					return $this->getCalendarExportData($user, $calendar);
				} catch (CalendarMigratorException $e) {
					throw new CalendarMigratorException();
				} catch (InvalidCalendarException $e) {
					// Allow this exception as invalid (e.g. deleted) calendars are not to be exported
				}
			},
			$this->calendarManager->getCalendarsForPrincipal($principalUri),
		)));
	}

	/**
	 * @return array{name: string, data: string}
	 *
	 * @throws CalendarMigratorException
	 * @throws InvalidCalendarException
	 */
	public function getCalendarExportData(IUser $user, ICalendar $calendar): array {
		$userId = $user->getUID();
		$calendarId = $calendar->getKey();
		$calendarInfo = $this->calDavBackend->getCalendarById($calendarId);

		if (!empty($calendarInfo)) {
			$uri = $calendarInfo['uri'];
			$path = CalDAVPlugin::CALENDAR_ROOT . "/$userId/$uri";

			// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::httpGet()

			$properties = $this->sabreDavServer->getProperties($path, [
				'{DAV:}resourcetype',
				'{DAV:}displayname',
				'{http://sabredav.org/ns}sync-token',
				'{DAV:}sync-token',
				'{http://apple.com/ns/ical/}calendar-color',
			]);

			// Filter out invalid (e.g. deleted) calendars
			if (!isset($properties['{DAV:}resourcetype']) || !$properties['{DAV:}resourcetype']->is('{' . CalDAVPlugin::NS_CALDAV . '}calendar')) {
				throw new InvalidCalendarException();
			}

			// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::generateResponse()

			$calDataProp = '{' . CalDAVPlugin::NS_CALDAV . '}calendar-data';
			$calendarNode = $this->sabreDavServer->tree->getNodeForPath($path);
			$nodes = $this->sabreDavServer->getPropertiesForPath($path, [$calDataProp], 1);

			$blobs = [];
			foreach ($nodes as $node) {
				if (isset($node[200][$calDataProp])) {
					$blobs[$node['href']] = $node[200][$calDataProp];
				}
			}

			$mergedCalendar = $this->icsExportPlugin->mergeObjects(
				$properties,
				$blobs,
			);

			return [
				'name' => $calendarNode->getName(),
				'data' => $mergedCalendar->serialize(),
			];
		}

		throw new CalendarMigratorException();
	}

	public function getUniqueCalendarUri(IUser $user, string $initialCalendarUri): string {
		$principalUri = $this->getPrincipalUri($user);
		$initialCalendarUri = "migrated-$initialCalendarUri";

		$existingCalendarUris = array_map(
			fn (ICalendar $calendar) => $calendar->getUri(),
			$this->calendarManager->getCalendarsForPrincipal($principalUri),
		);

		$calendarUri = $initialCalendarUri;
		$acc = 1;
		while (in_array($calendarUri, $existingCalendarUris, true)) {
			$calendarUri = $initialCalendarUri . "-$acc";
			++$acc;
		}

		return $calendarUri;
	}

	/**
	 * @throws CalendarMigratorException
	 */
	protected function writeExport(IUser $user, string $data, string $destDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();

		// setup filesystem
		// Requesting the user folder will set it up if the user hasn't logged in before
		\OC::$server->getUserFolder($userId);
		Filesystem::initMountPoints($userId);

		$view = new View();

		if ($view->file_put_contents("$destDir/$filename", $data) === false) {
			throw new CalendarMigratorException('Could not export calendar');
		}

		$output->writeln("<info>✅ Exported calendar of <$userId> into $destDir/$filename</info>");
	}

	public function export(IUser $user, OutputInterface $output): void {
		$userId = $user->getUID();

		try {
			$calendarExports = $this->getCalendarExports($user, $this->calDavBackend);
		} catch (CalendarMigratorException $e) {
			$output->writeln("<error>Error exporting <$userId> calendars</error>");
		}

		if (empty($calendarExports)) {
			$output->writeln("<info>User <$userId> has no calendars to export</info>");
			throw new CalendarMigratorException();
		}

		foreach ($calendarExports as ['name' => $name, 'data' => $data]) {
			// Set filename to sanitized calendar name appended with the date
			$filename = preg_replace('/[^a-zA-Z0-9-_ ]/um', '', $name) . '_' . date('Y-m-d') . CalendarMigrator::FILENAME_EXT;

			$this->writeExport(
				$user,
				$data,
				// TESTING directory does not automatically get created so just write to user directory, this will be put in a zip with all other user_migration data
				// "/$userId/export/$appId",
				"/$userId",
				$filename,
				$output,
			);
		}
	}

	/**
	 * Return an associative array mapping of Time Zone ID to VTimeZone component
	 *
	 * @return array<string, VTimeZone>
	 */
	public function getCalendarTimezones(VCalendar $vCalendar) {
		/** @var VTimeZone[] $calendarTimezones */
		$calendarTimezones = array_values(array_filter(
			$vCalendar->getComponents(),
			fn ($component) => $component->name === 'VTIMEZONE',
		));

		/** @var array<string, VTimeZone> $calendarTimezoneMap */
		$calendarTimezoneMap = [];
		foreach ($calendarTimezones as $vTimeZone) {
			$calendarTimezoneMap[$vTimeZone->getTimeZone()->getName()] = $vTimeZone;
		}

		return $calendarTimezoneMap;
	}

	/**
	 * @return VTimeZone[]
	 */
	public function getTimezonesForComponent(VCalendar $vCalendar, VObjectComponent $component) {
		$componentTimezoneIds = [];

		foreach ($component->children() as $subComp) {
			if ($subComp instanceof DateTime && isset($subComp->parameters['TZID'])) {
				if (!in_array($subComp->parameters['TZID']->getValue(), $componentTimezoneIds, true)) {
					$componentTimezoneIds[] = $subComp->parameters['TZID']->getValue();
				}
			}
		}

		$calendarTimezoneMap = $this->getCalendarTimezones($vCalendar);

		return array_values(array_filter(array_map(
			fn (string $timezoneId) => $calendarTimezoneMap[$timezoneId],
			$componentTimezoneIds,
		)));
	}

	public function getCleanComponent(VObjectComponent $component): VObjectComponent {
		$componentClone = clone $component;

		// Remove RSVP parameters to prevent automatically sending invitaton emails to attendees when importing this component
		foreach ($componentClone->children() as $subComp) {
			if (
				$subComp->name === 'ATTENDEE'
				&& isset($subComp->parameters['RSVP'])
			) {
				unset($subComp->parameters['RSVP']);
			}
		}

		return $componentClone;
	}

	/**
	 * @return VObjectComponent[]
	 */
	public function getComponentsForImport(VCalendar $vCalendar, VObjectComponent $component) {
		$cleanComponent = $this->getCleanComponent($component);
		return [
			...$this->getTimezonesForComponent($vCalendar, $cleanComponent),
			$cleanComponent,
		];
	}

	public function importCalendarObject(int $calendarId, VCalendar $vCalendarObject): void {
		try {
			$this->calDavBackend->createCalendarObject(
				$calendarId,
				UUIDUtil::getUUID() . CalendarMigrator::FILENAME_EXT,
				$vCalendarObject->serialize(),
				CalDavBackend::CALENDAR_TYPE_CALENDAR,
			);
		} catch (BadRequest $e) {
			// Rollback creation of calendar on error
			$this->calDavBackend->deleteCalendar($calendarId, true);
		}
	}

	public function initCalendarObject(): VCalendar {
		$vCalendarObject = new VCalendar();
		$vCalendarObject->PRODID = $this->sabreDavServer::$exposeVersion
			? '-//SabreDAV//SabreDAV ' . SabreDavVersion::VERSION . '//EN'
			: '-//SabreDAV//SabreDAV//EN';
		return $vCalendarObject;
	}

	// TODO Test import and export of various calendars within the scope of migration
	// - https://github.com/nextcloud/calendar/tree/main/tests/assets/ics
	// - https://github.com/nextcloud/calendar-js/tree/main/tests/assets
	// - https://github.com/nextcloud/server/tree/master/apps/dav/tests/travis/caldavtest/data/Resource/CalDAV/sharing/calendars/read-write
	// - https://github.com/nextcloud/tasks/tree/master/tests/assets/ics/vcalendars

	/**
	 * @throws CalendarMigratorException
	 */
	public function importCalendar(IUser $user, string $filename, string $initialCalendarUri, VCalendar $vCalendar): void {
		$principalUri = $this->getPrincipalUri($user);
		$calendarUri = $this->getUniqueCalendarUri($user, $initialCalendarUri);

		//  Implementation below based on https://github.com/nextcloud/cdav-library/blob/9b67034837fad9e8f764d0152211d46565bf01f2/src/models/calendarHome.js#L151

		$calendarId = $this->calDavBackend->createCalendar($principalUri, $calendarUri, [
			'{DAV:}displayname' => isset($vCalendar->{'X-WR-CALNAME'}) ? $vCalendar->{'X-WR-CALNAME'}->getValue() : $this->l10n->t('Migrated calendar (%1$s)', [$filename]),
			'{http://apple.com/ns/ical/}calendar-color' => isset($vCalendar->{'X-APPLE-CALENDAR-COLOR'}) ? $vCalendar->{'X-APPLE-CALENDAR-COLOR'}->getValue() : $this->defaults->getColorPrimary(),
			'components' => implode(
				',',
				array_reduce(
					$vCalendar->getComponents(),
					fn (array $componentNames, VObjectComponent $component) => !in_array($component->name, $componentNames, true) ? [...$componentNames, $component->name] : $componentNames,
					[],
				)
			),
		]);

		/** @var VObjectComponent[] $calendarComponents */
		$calendarComponents = array_values(array_filter(
			$vCalendar->getComponents(),
			fn ($component) => $component->name !== 'VTIMEZONE',
		));

		/** @var array<string, VObjectComponent[]> $groupedCalendarComponents */
		$groupedCalendarComponents = [];
		/** @var VObjectComponent[] $ungroupedCalendarComponents */
		$ungroupedCalendarComponents = [];

		foreach ($calendarComponents as $component) {
			if (isset($component->UID)) {
				$uid = $component->UID->getValue();
				// Components (e.g. VEVENT) with the same UID are recurring
				if (isset($groupedCalendarComponents[$uid])) {
					$groupedCalendarComponents[$uid][] = $component;
				} else {
					$groupedCalendarComponents[$uid] = [$component];
				}
			} else {
				$ungroupedCalendarComponents[] = $component;
			}
		}

		// Implementation used the below as references:
		// - https://github.com/nextcloud/calendar/blob/1aadc6101ea1dcea578bce1e7c626ddaef911b79/src/store/calendars.js#L1000
		// - https://github.com/nextcloud/calendar-js/blob/43774b6563502fe31ace6072a75fbe12d2f3cb85/src/parsers/icalendarParser.js#L187
		// - https://github.com/nextcloud/calendar-js/blob/43774b6563502fe31ace6072a75fbe12d2f3cb85/src/parsers/parserManager.js#L67
		// - https://github.com/nextcloud/calendar/blob/1aadc6101ea1dcea578bce1e7c626ddaef911b79/src/components/AppNavigation/Settings/SettingsImportSection.vue#L201-L208

		foreach ($groupedCalendarComponents as $uid => $componentGroup) {
			// Construct and import a calendar object containing all components of a group
			$vCalendarObject = $this->initCalendarObject();
			foreach ($componentGroup as $component) {
				foreach ($this->getComponentsForImport($vCalendar, $component) as $component) {
					$vCalendarObject->add($component);
				}
			}
			$this->importCalendarObject($calendarId, $vCalendarObject);
		}

		foreach ($ungroupedCalendarComponents as $component) {
			// Construct and import a calendar object for a single component
			$vCalendarObject = $this->initCalendarObject();
			foreach ($this->getComponentsForImport($vCalendar, $component) as $component) {
				$vCalendarObject->add($component);
			}
			$this->importCalendarObject($calendarId, $vCalendarObject);
		}
	}

	/**
	 * @throws FilesystemException
	 * @throws CalendarMigratorException
	 */
	public function import(IUser $user, string $srcDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();

		try {
			/** @var VCalendar $vCalendar */
			$vCalendar = VObjectReader::read(
				fopen("$srcDir/$filename", 'r'),
				VObjectReader::OPTION_FORGIVING,
			);
		} catch (FilesystemException $e) {
			throw new FilesystemException("Failed to read file: \"$srcDir/$filename\"");
		}

		$problems = $vCalendar->validate();

		if (empty($problems)) {
			$splitFilename = explode('_', $filename, 2);
			if (empty($splitFilename) || count($splitFilename) !== 2) {
				$output->writeln("<error>Invalid filename, filename must be of the format: \"<calendar_name>_YYYY-MM-DD" . CalendarMigrator::FILENAME_EXT . "\"</error>");
				throw new CalendarMigratorException();
			}
			[$initialCalendarUri, $suffix] = $splitFilename;

			$this->importCalendar(
				$user,
				$filename,
				$initialCalendarUri,
				$vCalendar,
				$this->calDavBackend
			);
			$vCalendar->destroy();

			$output->writeln("<info>✅ Imported calendar \"$filename\" into account of <$userId></info>");
			throw new CalendarMigratorException();
		}

		throw new CalendarMigratorException("Invalid iCalendar data in $srcDir/$filename");
	}
}
