<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Christoph Wurst <christoph@owncloud.com>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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
namespace OCA\DAV;

use OCA\DAV\CalDAV\Schedule\IMipPlugin;
use OCA\DAV\CardDAV\ImageExportPlugin;
use OCA\DAV\Comments\CommentsPlugin;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\DAV\Connector\Sabre\BlockLegacyClientPlugin;
use OCA\DAV\Connector\Sabre\CopyEtagHeaderPlugin;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\DummyGetResponsePlugin;
use OCA\DAV\Connector\Sabre\FakeLockerPlugin;
use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\DAV\DAV\PublicAuth;
use OCA\DAV\Connector\Sabre\QuotaPlugin;
use OCA\DAV\Files\BrowserErrorPagePlugin;
use OCA\DAV\Files\CustomPropertiesBackend;
use OCA\DAV\SystemTag\SystemTagPlugin;
use OCP\IRequest;
use OCP\SabrePluginEvent;
use Sabre\CardDAV\VCFExportPlugin;
use Sabre\DAV\Auth\Plugin;
use OCA\DAV\Connector\Sabre\TagsPlugin;

class Server {

	/** @var IRequest */
	private $request;

	public function __construct(IRequest $request, $baseUri) {
		$this->request = $request;
		$this->baseUri = $baseUri;
		$logger = \OC::$server->getLogger();
		$mailer = \OC::$server->getMailer();
		$dispatcher = \OC::$server->getEventDispatcher();

		$root = new RootCollection();
		$this->server = new \OCA\DAV\Connector\Sabre\Server($root);

		// Backends
		$authBackend = new Auth(
			\OC::$server->getSession(),
			\OC::$server->getUserSession(),
			\OC::$server->getRequest(),
			\OC::$server->getTwoFactorAuthManager()
		);

		// Set URL explicitly due to reverse-proxy situations
		$this->server->httpRequest->setUrl($this->request->getRequestUri());
		$this->server->setBaseUri($this->baseUri);

		$this->server->addPlugin(new BlockLegacyClientPlugin(\OC::$server->getConfig()));
		$authPlugin = new Plugin();
		$authPlugin->addBackend($authBackend);
		$authPlugin->addBackend(new PublicAuth());
		$this->server->addPlugin($authPlugin);

		// allow setup of additional auth backends
		$event = new SabrePluginEvent($this->server);
		$dispatcher->dispatch('OCA\DAV\Connector\Sabre::authInit', $event);

		// because we are throwing exceptions this plugin has to be the last one
		$authPlugin->addBackend($authBackend);

		// debugging
		if(\OC::$server->getConfig()->getSystemValue('debug', false)) {
			$this->server->addPlugin(new \Sabre\DAV\Browser\Plugin());
		} else {
			$this->server->addPlugin(new DummyGetResponsePlugin());
		}

		$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin('webdav', $logger));
		$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\LockPlugin());
		$this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());

		// acl
		$acl = new DavAclPlugin();
		$acl->principalCollectionSet = [
			'principals/users', 'principals/groups'
		];
		$acl->defaultUsernamePath = 'principals/users';
		$this->server->addPlugin($acl);

		// calendar plugins
		$this->server->addPlugin(new \OCA\DAV\CalDAV\Plugin());
		$this->server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
		$this->server->addPlugin(new \Sabre\CalDAV\Schedule\Plugin());
		$this->server->addPlugin(new IMipPlugin($mailer, $logger));
		$this->server->addPlugin(new \Sabre\CalDAV\Subscriptions\Plugin());
		$this->server->addPlugin(new \Sabre\CalDAV\Notifications\Plugin());
		$this->server->addPlugin(new DAV\Sharing\Plugin($authBackend, \OC::$server->getRequest()));
		$this->server->addPlugin(new \OCA\DAV\CalDAV\Publishing\PublishPlugin(
			\OC::$server->getConfig(),
			\OC::$server->getURLGenerator()
		));

		// addressbook plugins
		$this->server->addPlugin(new \OCA\DAV\CardDAV\Plugin());
		$this->server->addPlugin(new VCFExportPlugin());
		$this->server->addPlugin(new ImageExportPlugin(\OC::$server->getLogger()));

		// system tags plugins
		$this->server->addPlugin(new SystemTagPlugin(
			\OC::$server->getSystemTagManager(),
			\OC::$server->getGroupManager(),
			\OC::$server->getUserSession()
		));

		// comments plugin
		$this->server->addPlugin(new CommentsPlugin(
			\OC::$server->getCommentsManager(),
			\OC::$server->getUserSession()
		));

		$this->server->addPlugin(new CopyEtagHeaderPlugin());

		// Some WebDAV clients do require Class 2 WebDAV support (locking), since
		// we do not provide locking we emulate it using a fake locking plugin.
		if($request->isUserAgent([
			'/WebDAVFS/',
			'/Microsoft Office OneNote 2013/',
		])) {
			$this->server->addPlugin(new FakeLockerPlugin());
		}

		if (BrowserErrorPagePlugin::isBrowserRequest($request)) {
			$this->server->addPlugin(new BrowserErrorPagePlugin());
		}

		// wait with registering these until auth is handled and the filesystem is setup
		$this->server->on('beforeMethod', function () {
			// custom properties plugin must be the last one
			$userSession = \OC::$server->getUserSession();
			$user = $userSession->getUser();
			if (!is_null($user)) {
				$view = \OC\Files\Filesystem::getView();
				$this->server->addPlugin(
					new FilesPlugin(
						$this->server->tree,
						$view,
						\OC::$server->getConfig(),
						$this->request,
						false,
						!\OC::$server->getConfig()->getSystemValue('debug', false)
					)
				);

				$this->server->addPlugin(
					new \Sabre\DAV\PropertyStorage\Plugin(
						new CustomPropertiesBackend(
							$this->server->tree,
							\OC::$server->getDatabaseConnection(),
							\OC::$server->getUserSession()->getUser()
						)
					)
				);
				$this->server->addPlugin(
					new QuotaPlugin($view));
				$this->server->addPlugin(
					new TagsPlugin(
						$this->server->tree, \OC::$server->getTagManager()
					)
				);
				// TODO: switch to LazyUserFolder
				$userFolder = \OC::$server->getUserFolder();
				$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\SharesPlugin(
					$this->server->tree,
					$userSession,
					$userFolder,
					\OC::$server->getShareManager()
				));
				$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\CommentPropertiesPlugin(
					\OC::$server->getCommentsManager(),
					$userSession
				));
				$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\FilesReportPlugin(
					$this->server->tree,
					$view,
					\OC::$server->getSystemTagManager(),
					\OC::$server->getSystemTagObjectMapper(),
					\OC::$server->getTagManager(),
					$userSession,
					\OC::$server->getGroupManager(),
					$userFolder
				));
			}
			$this->server->addPlugin(new \OCA\DAV\Connector\Sabre\CopyEtagHeaderPlugin());
		});
	}

	public function exec() {
		$this->server->exec();
	}
}
