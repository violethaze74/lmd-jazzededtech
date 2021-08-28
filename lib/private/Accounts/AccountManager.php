<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2016, Björn Schießle
 *
 * @author Bjoern Schiessle <bjoern@schiessle.org>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Daniel Calviño Sánchez <danxuliu@gmail.com>
 * @author Daniel Kesselberg <mail@danielkesselberg.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\Accounts;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use OCA\Settings\BackgroundJobs\VerifyUserData;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountPropertyCollection;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use function array_flip;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function json_last_error;

/**
 * Class AccountManager
 *
 * Manage system accounts table
 *
 * @group DB
 * @package OC\Accounts
 */
class AccountManager implements IAccountManager {
	use TAccountsHelper;

	/** @var  IDBConnection database connection */
	private $connection;

	/** @var IConfig */
	private $config;

	/** @var string table name */
	private $table = 'accounts';

	/** @var string table name */
	private $dataTable = 'accounts_data';

	/** @var EventDispatcherInterface */
	private $eventDispatcher;

	/** @var IJobList */
	private $jobList;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(IDBConnection $connection,
								IConfig $config,
								EventDispatcherInterface $eventDispatcher,
								IJobList $jobList,
								LoggerInterface $logger) {
		$this->connection = $connection;
		$this->config = $config;
		$this->eventDispatcher = $eventDispatcher;
		$this->jobList = $jobList;
		$this->logger = $logger;
	}

	/**
	 * @param string $input
	 * @return string Provided phone number in E.164 format when it was a valid number
	 * @throws InvalidArgumentException When the phone number was invalid or no default region is set and the number doesn't start with a country code
	 */
	protected function parsePhoneNumber(string $input): string {
		$defaultRegion = $this->config->getSystemValueString('default_phone_region', '');

		if ($defaultRegion === '') {
			// When no default region is set, only +49… numbers are valid
			if (strpos($input, '+') !== 0) {
				throw new InvalidArgumentException(self::PROPERTY_PHONE);
			}

			$defaultRegion = 'EN';
		}

		$phoneUtil = PhoneNumberUtil::getInstance();
		try {
			$phoneNumber = $phoneUtil->parse($input, $defaultRegion);
			if ($phoneNumber instanceof PhoneNumber && $phoneUtil->isValidNumber($phoneNumber)) {
				return $phoneUtil->format($phoneNumber, PhoneNumberFormat::E164);
			}
		} catch (NumberParseException $e) {
		}

		throw new InvalidArgumentException(self::PROPERTY_PHONE);
	}

	/**
	 *
	 * @param string $input
	 * @return string
	 * @throws InvalidArgumentException When the website did not have http(s) as protocol or the host name was empty
	 */
	protected function parseWebsite(string $input): string {
		$parts = parse_url($input);
		if (!isset($parts['scheme']) || ($parts['scheme'] !== 'https' && $parts['scheme'] !== 'http')) {
			throw new InvalidArgumentException(self::PROPERTY_WEBSITE);
		}

		if (!isset($parts['host']) || $parts['host'] === '') {
			throw new InvalidArgumentException(self::PROPERTY_WEBSITE);
		}

		return $input;
	}

	/**
	 * @param IAccountProperty[] $properties
	 */
	protected function testValueLengths(array $properties, bool $throwOnData = false): void {
		foreach ($properties as $property) {
			if (strlen($property->getValue()) > 2048) {
				if ($throwOnData) {
					throw new InvalidArgumentException();
				} else {
					$property->setValue('');
				}
			}
		}
	}

	protected function testPropertyScope(IAccountProperty $property, array $allowedScopes, bool $throwOnData): void {
		if ($throwOnData && !in_array($property->getScope(), $allowedScopes, true)) {
			throw new InvalidArgumentException('scope');
		}

		if (
			$property->getScope() === self::SCOPE_PRIVATE
			&& in_array($property->getName(), [self::PROPERTY_DISPLAYNAME, self::PROPERTY_EMAIL])
		) {
			if ($throwOnData) {
				// v2-private is not available for these fields
				throw new InvalidArgumentException('scope');
			} else {
				// default to local
				$property->setScope(self::SCOPE_LOCAL);
			}
		} else {
			// migrate scope values to the new format
			// invalid scopes are mapped to a default value
			$property->setScope(AccountProperty::mapScopeToV2($property->getScope()));
		}
	}

	protected function sanitizePhoneNumberValue(IAccountProperty $property, bool $throwOnData = false) {
		if ($property->getName() !== self::PROPERTY_PHONE) {
			if ($throwOnData) {
				throw new InvalidArgumentException(sprintf('sanitizePhoneNumberValue can only sanitize phone numbers, %s given', $property->getName()));
			}
			return;
		}
		if ($property->getValue() === '') {
			return;
		}
		try {
			$property->setValue($this->parsePhoneNumber($property->getValue()));
		} catch (InvalidArgumentException $e) {
			if ($throwOnData) {
				throw $e;
			}
			$property->setValue('');
		}
	}

	protected function sanitizeWebsite(IAccountProperty $property, bool $throwOnData = false) {
		if ($property->getName() !== self::PROPERTY_WEBSITE) {
			if ($throwOnData) {
				throw new InvalidArgumentException(sprintf('sanitizeWebsite can only sanitize web domains, %s given', $property->getName()));
			}
		}
		try {
			$property->setValue($this->parseWebsite($property->getValue()));
		} catch (InvalidArgumentException $e) {
			if ($throwOnData) {
				throw $e;
			}
			$property->setValue('');
		}
	}

	protected function updateUser(IUser $user, array $data, bool $throwOnData = false): array {
		$oldUserData = $this->getUser($user, false);
		$updated = true;

		if ($oldUserData !== $data) {
			$this->updateExistingUser($user, $data);
		} else {
			// nothing needs to be done if new and old data set are the same
			$updated = false;
		}

		if ($updated) {
			$this->eventDispatcher->dispatch(
				'OC\AccountManager::userUpdated',
				new GenericEvent($user, $data)
			);
		}

		return $data;
	}

	/**
	 * delete user from accounts table
	 *
	 * @param IUser $user
	 */
	public function deleteUser(IUser $user) {
		$uid = $user->getUID();
		$query = $this->connection->getQueryBuilder();
		$query->delete($this->table)
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->execute();

		$this->deleteUserData($user);
	}

	/**
	 * delete user from accounts table
	 *
	 * @param IUser $user
	 */
	public function deleteUserData(IUser $user): void {
		$uid = $user->getUID();
		$query = $this->connection->getQueryBuilder();
		$query->delete($this->dataTable)
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->execute();
	}

	/**
	 * get stored data from a given user
	 */
	protected function getUser(IUser $user, bool $insertIfNotExists = true): array {
		$uid = $user->getUID();
		$query = $this->connection->getQueryBuilder();
		$query->select('data')
			->from($this->table)
			->where($query->expr()->eq('uid', $query->createParameter('uid')))
			->setParameter('uid', $uid);
		$result = $query->executeQuery();
		$accountData = $result->fetchAll();
		$result->closeCursor();

		if (empty($accountData)) {
			$userData = $this->buildDefaultUserRecord($user);
			if ($insertIfNotExists) {
				$this->insertNewUser($user, $userData);
			}
			return $userData;
		}

		$userDataArray = $this->importFromJson($accountData[0]['data'], $uid);
		if ($userDataArray === null || $userDataArray === []) {
			return $this->buildDefaultUserRecord($user);
		}

		return $this->addMissingDefaultValues($userDataArray);
	}

	public function searchUsers(string $property, array $values): array {
		$chunks = array_chunk($values, 500);
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from($this->dataTable)
			->where($query->expr()->eq('name', $query->createNamedParameter($property)))
			->andWhere($query->expr()->in('value', $query->createParameter('values')));

		$matches = [];
		foreach ($chunks as $chunk) {
			$query->setParameter('values', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
			$result = $query->executeQuery();

			while ($row = $result->fetch()) {
				$matches[$row['uid']] = $row['value'];
			}
			$result->closeCursor();
		}

		$result = array_merge($matches, $this->searchUsersForRelatedCollection($property, $values));

		return array_flip($result);
	}

	protected function searchUsersForRelatedCollection(string $property, array $values): array {
		switch ($property) {
			case IAccountManager::PROPERTY_EMAIL:
				return array_flip($this->searchUsers(IAccountManager::COLLECTION_EMAIL, $values));
			default:
				return [];
		}
	}

	/**
	 * check if we need to ask the server for email verification, if yes we create a cronjob
	 *
	 */
	protected function checkEmailVerification(IAccount $updatedAccount, array $oldData): void {
		try {
			$property = $updatedAccount->getProperty(self::PROPERTY_EMAIL);
		} catch (PropertyDoesNotExistException $e) {
			return;
		}
		$oldMail = isset($oldData[self::PROPERTY_EMAIL]) ? $oldData[self::PROPERTY_EMAIL]['value']['value'] : '';
		if ($oldMail !== $property->getValue()) {
			$this->jobList->add(VerifyUserData::class,
				[
					'verificationCode' => '',
					'data' => $property->getValue(),
					'type' => self::PROPERTY_EMAIL,
					'uid' => $updatedAccount->getUser()->getUID(),
					'try' => 0,
					'lastRun' => time()
				]
			);




			$property->setVerified(self::VERIFICATION_IN_PROGRESS);
		}
	}

	/**
	 * make sure that all expected data are set
	 *
	 */
	protected function addMissingDefaultValues(array $userData): array {
		foreach ($userData as $i => $value) {
			if (!isset($value['verified'])) {
				$userData[$i]['verified'] = self::NOT_VERIFIED;
			}
		}

		return $userData;
	}

	protected function updateVerificationStatus(IAccount $updatedAccount, array $oldData): void {
		static $propertiesVerifiableByLookupServer = [
			self::PROPERTY_TWITTER,
			self::PROPERTY_WEBSITE,
			self::PROPERTY_EMAIL,
		];

		foreach ($propertiesVerifiableByLookupServer as $propertyName) {
			try {
				$property = $updatedAccount->getProperty($propertyName);
			} catch (PropertyDoesNotExistException $e) {
				continue;
			}
			$wasVerified = isset($oldData[$propertyName])
				&& isset($oldData[$propertyName]['verified'])
				&& $oldData[$propertyName]['verified'] === self::VERIFIED;
			if ((!isset($oldData[$propertyName])
					|| !isset($oldData[$propertyName]['value'])
					|| $property->getValue() !== $oldData[$propertyName]['value'])
				&& ($property->getVerified() !== self::NOT_VERIFIED
					|| $wasVerified)
				) {
				$property->setVerified(self::NOT_VERIFIED);
			}
		}
	}


	/**
	 * add new user to accounts table
	 *
	 * @param IUser $user
	 * @param array $data
	 */
	protected function insertNewUser(IUser $user, array $data): void {
		$uid = $user->getUID();
		$jsonEncodedData = $this->prepareJson($data);
		$query = $this->connection->getQueryBuilder();
		$query->insert($this->table)
			->values(
				[
					'uid' => $query->createNamedParameter($uid),
					'data' => $query->createNamedParameter($jsonEncodedData),
				]
			)
			->executeStatement();

		$this->deleteUserData($user);
		$this->writeUserData($user, $data);
	}

	protected function prepareJson(array $data): string {
		$preparedData = [];
		foreach ($data as $dataRow) {
			$propertyName = $dataRow['name'];
			unset($dataRow['name']);
			if (!$this->isCollection($propertyName)) {
				$preparedData[$propertyName] = $dataRow;
				continue;
			}
			if (!isset($preparedData[$propertyName])) {
				$preparedData[$propertyName] = [];
			}
			$preparedData[$propertyName][] = $dataRow;
		}
		return json_encode($preparedData);
	}

	protected function importFromJson(string $json, string $userId): ?array {
		$result = [];
		$jsonArray = json_decode($json, true);
		$jsonError = json_last_error();
		if ($jsonError !== JSON_ERROR_NONE) {
			$this->logger->critical(
				'User data of {uid} contained invalid JSON (error {json_error}), hence falling back to a default user record',
				[
					'uid' => $userId,
					'json_error' => $jsonError
				]
			);
			return null;
		}
		foreach ($jsonArray as $propertyName => $row) {
			if (!$this->isCollection($propertyName)) {
				$result[] = array_merge($row, ['name' => $propertyName]);
				continue;
			}
			foreach ($row as $singleRow) {
				$result[] = array_merge($singleRow, ['name' => $propertyName]);
			}
		}
		return $result;
	}

	/**
	 * update existing user in accounts table
	 *
	 * @param IUser $user
	 * @param array $data
	 */
	protected function updateExistingUser(IUser $user, array $data): void {
		$uid = $user->getUID();
		$jsonEncodedData = $this->prepareJson($data);
		$query = $this->connection->getQueryBuilder();
		$query->update($this->table)
			->set('data', $query->createNamedParameter($jsonEncodedData))
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();

		$this->deleteUserData($user);
		$this->writeUserData($user, $data);
	}

	protected function writeUserData(IUser $user, array $data): void {
		$query = $this->connection->getQueryBuilder();
		$query->insert($this->dataTable)
			->values(
				[
					'uid' => $query->createNamedParameter($user->getUID()),
					'name' => $query->createParameter('name'),
					'value' => $query->createParameter('value'),
				]
			);
		$this->writeUserDataProperties($query, $data);
	}

	protected function writeUserDataProperties(IQueryBuilder $query, array $data): void {
		foreach ($data as $property) {
			if ($property['name'] === self::PROPERTY_AVATAR) {
				continue;
			}


			$query->setParameter('name', $property['name'])
				->setParameter('value', $property['value'] ?? '');
			$query->executeStatement();
		}
	}

	/**
	 * build default user record in case not data set exists yet
	 *
	 * @param IUser $user
	 * @return array
	 */
	protected function buildDefaultUserRecord(IUser $user) {
		return [

			[
				'name' => self::PROPERTY_DISPLAYNAME,
				'value' => $user->getDisplayName(),
				'scope' => self::SCOPE_FEDERATED,
				'verified' => self::NOT_VERIFIED,
			],

			[
				'name' => self::PROPERTY_ADDRESS,
				'value' => '',
				'scope' => self::SCOPE_LOCAL,
				'verified' => self::NOT_VERIFIED,
			],

			[
				'name' => self::PROPERTY_WEBSITE,
				'value' => '',
				'scope' => self::SCOPE_LOCAL,
				'verified' => self::NOT_VERIFIED,
			],

			[
				'name' => self::PROPERTY_EMAIL,
				'value' => $user->getEMailAddress(),
				'scope' => self::SCOPE_FEDERATED,
				'verified' => self::NOT_VERIFIED,
			],

			[
				'name' => self::PROPERTY_AVATAR,
				'scope' => self::SCOPE_FEDERATED
			],

			[
				'name' => self::PROPERTY_PHONE,
				'value' => '',
				'scope' => self::SCOPE_LOCAL,
				'verified' => self::NOT_VERIFIED,
			],

			[
				'name' => self::PROPERTY_TWITTER,
				'value' => '',
				'scope' => self::SCOPE_LOCAL,
				'verified' => self::NOT_VERIFIED,
			],

		];
	}

	private function arrayDataToCollection(IAccount $account, array $data): IAccountPropertyCollection {
		$collection = $account->getPropertyCollection($data['name']);

		$p = new AccountProperty(
			$data['name'],
			$data['value'] ?? '',
			$data['scope'] ?? self::SCOPE_LOCAL,
			$data['verified'] ?? self::NOT_VERIFIED,
			''
		);
		$collection->addProperty($p);

		return $collection;
	}

	private function parseAccountData(IUser $user, $data): Account {
		$account = new Account($user);
		foreach ($data as $accountData) {
			if ($this->isCollection($accountData['name'])) {
				$account->setPropertyCollection($this->arrayDataToCollection($account, $accountData));
			} else {
				$account->setProperty($accountData['name'], $accountData['value'] ?? '', $accountData['scope'] ?? self::SCOPE_LOCAL, $accountData['verified'] ?? self::NOT_VERIFIED);
			}
		}
		return $account;
	}

	public function getAccount(IUser $user): IAccount {
		return $this->parseAccountData($user, $this->getUser($user));
	}

	public function updateAccount(IAccount $account): void {
		$this->testValueLengths(iterator_to_array($account->getAllProperties()), true);
		try {
			$property = $account->getProperty(self::PROPERTY_PHONE);
			$this->sanitizePhoneNumberValue($property);
		} catch (PropertyDoesNotExistException $e) {
			//  valid case, nothing to do
		}

		try {
			$property = $account->getProperty(self::PROPERTY_WEBSITE);
			$this->sanitizeWebsite($property);
		} catch (PropertyDoesNotExistException $e) {
			//  valid case, nothing to do
		}

		static $allowedScopes = [
			self::SCOPE_PRIVATE,
			self::SCOPE_LOCAL,
			self::SCOPE_FEDERATED,
			self::SCOPE_PUBLISHED,
			self::VISIBILITY_PRIVATE,
			self::VISIBILITY_CONTACTS_ONLY,
			self::VISIBILITY_PUBLIC,
		];
		foreach ($account->getAllProperties() as $property) {
			$this->testPropertyScope($property, $allowedScopes, true);
		}

		$oldData = $this->getUser($account->getUser(), false);
		$this->updateVerificationStatus($account, $oldData);
		$this->checkEmailVerification($account, $oldData);

		$data = [];
		foreach ($account->getAllProperties() as $property) {
			$data[] = [
				'name' => $property->getName(),
				'value' => $property->getValue(),
				'scope' => $property->getScope(),
				'verified' => $property->getVerified(),
			];
		}

		$this->updateUser($account->getUser(), $data, true);
	}
}