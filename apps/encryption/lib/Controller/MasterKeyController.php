<?php
/**
 * @author Sujith Haridasan <sharidasan@owncloud.com>
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

namespace OCA\Encryption\Controller;

use OC\Encryption\Exceptions\DecryptionFailedException;
use OC\Encryption\Manager;
use OC\Files\View;
use OCA\Encryption\KeyManager;
use OCA\Encryption\Util;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IUserManager;


class MasterKeyController extends  Controller {

	/** @var  Manager */
	private $encryptionManager;

	/** @var  IUserManager */
	private $userManager;

	/** @var  View */
	private $rootView;

	/** @var  KeyManager */
	private $keyManager;

	/** @var  Util */
	private $util;

	/** @var  array files which couldn't be decrypted */
	protected $failed;


	/**
	 * MasterKeyController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param Manager $encryptionManager
	 * @param IUserManager $userManager
	 * @param View $rootView
	 * @param KeyManager $keyManager
	 * @param Util $util
	 */
	public function __construct(
		$appName, IRequest $request, Manager $encryptionManager,
		IUserManager $userManager, View $rootView,
		KeyManager $keyManager, Util $util) {
		parent::__construct($appName, $request);
		$this->encryptionManager = $encryptionManager;
		$this->userManager = $userManager;
		$this->rootView = $rootView;
		$this->keyManager = $keyManager;
		$this->util = $util;
	}

	public function createNewMasterKey() {
		\OC::$server->getLogger()->warning(__METHOD__." so time to create new key!!!", ['app' => __CLASS__]);
		// First decrypt the users files
		$this->decryptAllUsersFiles();

		if (empty($this->failed)) {
			//Now recreate new encryption
			//Delete the encryption app
			\OC::$server->getAppConfig()->deleteApp('encryption');
			//Delete the files_encryption dir
			$this->rootView->deleteAll('files_encryption');
			\OC::$server->getConfig()->deleteAppValue('files_encryption','installed_version');
			\OC::$server->getConfig()->deleteAppValues('encryption');

			//Re-enable the encryption app
			\OC_App::enable('encryption');
			//Set masterkey it.
			\OC::$server->getAppConfig()->setValue('encryption','useMasterKey', '1');
		}

	}

	public function reencryptFiles() {
		//Now encrypt FS again
		$this->keyManager->validateMasterKey();
		$this->encryptAllUsersFiles();
	}

	public function encryptAllUsersFiles() {
		$this->encryptAllUserFilesWithMasterKey();
	}

	public function encryptAllUserFilesWithMasterKey() {
		$userNo = 1;
		foreach($this->userManager->getBackends() as $backend) {
			$limit = 500;
			$offset = 0;
			do {
				$users = $backend->getUsers('', $limit, $offset);
				foreach ($users as $user) {
					echo "\n Entering user $user\n";
					$userCount = "$user ($userNo)";
					$this->encryptUsersFiles($user, $userCount);
					$userNo++;
				}
				$offset += $limit;
			} while(count($users) >= $limit);
		}
	}

	public function encryptUsersFiles($uid, $userCount) {

		$this->setupUserFS($uid);
		$directories = [];
		$directories[] =  '/' . $uid . '/files';

		while($root = array_pop($directories)) {
			$content = $this->rootView->getDirectoryContent($root);
			foreach ($content as $file) {
				$path = $root . '/' . $file['name'];
				if ($this->rootView->is_dir($path)) {
					$directories[] = $path;
					continue;
				} else {
					if($this->encryptFile($path) === false) {
					}
				}
			}
		}
	}

	public function encryptFile($path) {
		$source = $path;
		$target = $path . '.encrypted.' . time();

		try {
			\OC::$server->getSession()->set('encryptAllCmd', true);
			$this->rootView->copy($source, $target);
			$this->rootView->rename($target, $source);
			\OC::$server->getSession()->set('encryptAllCmd', true);
		} catch (DecryptionFailedException $e) {
			if ($this->rootView->file_exists($target)) {
				$this->rootView->unlink($target);
			}
			return false;
		}

		return true;
	}

	public function decryptAllUsersFiles() {
		$userList = [];

		foreach ($this->userManager->getBackends() as $backend) {
			$limit = 500;
			$offset = 0;
			do {
				$users = $backend->getUsers('', $limit, $offset);
				foreach ($users as $user) {
					$userList[] = $user;
				}
				$offset += $limit;
			} while (count($users) >= $limit);
		}

		$numberOfUsers = count($userList);
		$userNo = 1;
		foreach ($userList as $uid) {
			$userCount = "$uid ($userNo of $numberOfUsers)";
			$this->decryptUsersFiles($uid, $userCount);
			$userNo++;
		}
	}

	public function decryptUsersFiles($uid, $userCount) {

		$this->setupUserFS($uid);
		$directories = [];
		$directories[] = '/' . $uid . '/files';

		while ($root = array_pop($directories)) {
			$content = $this->rootView->getDirectoryContent($root);
			foreach ($content as $file) {
				// only decrypt files owned by the user
				if($file->getStorage()->instanceOfStorage('OCA\Files_Sharing\SharedStorage')) {
					continue;
				}
				$path = $root . '/' . $file['name'];
				if ($this->rootView->is_dir($path)) {
					$directories[] = $path;
					continue;
				} else {
					try {
						if ($file->isEncrypted() === false) {
						} else {
							if ($this->decryptFile($path) === false) {
							}
						}
					} catch (\Exception $e) {
						if (isset($this->failed[$uid])) {
							$this->failed[$uid][] = $path;
						} else {
							$this->failed[$uid] = [$path];
						}
					}
				}
			}
		}

		if (empty($this->failed)) {
			$this->rootView->deleteAll("$uid/files_encryption");
		}
	}

	protected function decryptFile($path) {

		$source = $path;
		$target = $path . '.decrypted.' . $this->getTimestamp();

		try {
			\OC::$server->getSession()->set('decryptAllCmd', true);
			$this->rootView->copy($source, $target);
			$this->rootView->rename($target, $source);
			\OC::$server->getSession()->remove('decryptAllCmd');
		} catch (DecryptionFailedException $e) {
			if ($this->rootView->file_exists($target)) {
				$this->rootView->unlink($target);
			}
			return false;
		}

		return true;
	}

	protected function getTimestamp() {
		return time();
	}


	protected function setupUserFS($uid) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($uid);
	}
}