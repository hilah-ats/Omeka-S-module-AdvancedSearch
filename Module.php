<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2018
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Search;

use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, \Search\Api\Adapter\SearchPageAdapter::class);
        $acl->allow(null, \Search\Api\Adapter\SearchIndexAdapter::class);
        $acl->allow(null, \Search\Entity\SearchPage::class, 'read');
        $acl->allow(null, \Search\Entity\SearchIndex::class, 'read');
        $acl->allow(null, 'Search\Controller\Index');

        $this->addRoutes();
    }

    public function init(ModuleManager $moduleManager)
    {
        $event = $moduleManager->getEvent();
        $container = $event->getParam('ServiceManager');
        $serviceListener = $container->get('ServiceListener');

        $serviceListener->addServiceManager(
            'Search\AdapterManager',
            'search_adapters',
            Feature\AdapterProviderInterface::class,
            'getSearchAdapterConfig'
        );
        $serviceListener->addServiceManager(
            'Search\FormAdapterManager',
            'search_form_adapters',
            Feature\FormAdapterProviderInterface::class,
            'getSearchFormAdapterConfig'
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $sql = <<<'SQL'
CREATE TABLE search_index (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    adapter VARCHAR(255) NOT NULL,
    settings LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE search_page (
    id INT AUTO_INCREMENT NOT NULL,
    index_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(255) NOT NULL,
    form_adapter VARCHAR(255) NOT NULL,
    settings LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    INDEX IDX_4F10A34984337261 (index_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE search_page ADD CONSTRAINT FK_4F10A34984337261 FOREIGN KEY (index_id) REFERENCES search_index (id) ON DELETE CASCADE;
SQL;
        $connection = $serviceLocator->get('Omeka\Connection');
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function upgrade($oldVersion, $newVersion,
        ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');

        if (version_compare($oldVersion, '0.1.1', '<')) {
            $connection->exec('
                ALTER TABLE search_page
                CHANGE `form` `form_adapter` varchar(255) NOT NULL
            ');
        }

        if (version_compare($oldVersion, '0.5.0', '<')) {
            $sql = <<<'SQL'
ALTER TABLE search_page DROP FOREIGN KEY search_page_ibfk_1;
ALTER TABLE search_index CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE settings settings LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
ALTER TABLE search_page CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE index_id index_id INT NOT NULL AFTER id, CHANGE settings settings LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
DROP INDEX index_id ON search_page;
CREATE INDEX IDX_4F10A34984337261 ON search_page (index_id);
ALTER TABLE search_page ADD CONSTRAINT search_page_ibfk_1 FOREIGN KEY (index_id) REFERENCES search_index (id);
SQL;
            $sqls = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($sqls as $sql) {
                $connection->exec($sql);
            }
        }

        if (version_compare($oldVersion, '0.5.1', '<')) {
            $sql = <<<'SQL'
ALTER TABLE search_page DROP FOREIGN KEY FK_4F10A34984337261;
ALTER TABLE search_page ADD CONSTRAINT FK_4F10A34984337261 FOREIGN KEY (index_id) REFERENCES search_index (id) ON DELETE CASCADE;
SQL;
            $sqls = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($sqls as $sql) {
                $connection->exec($sql);
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $sql = <<<'SQL'
DROP TABLE IF EXISTS `search_page`;
DROP TABLE IF EXISTS `search_index`;
SQL;
        $connection = $serviceLocator->get('Omeka\Connection');
        $sqls = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'updateSearchIndex']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'updateSearchIndex']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.post',
            [$this, 'updateSearchIndex']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.create.post',
            [$this, 'updateSearchIndex']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.post',
            [$this, 'updateSearchIndex']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.delete.post',
            [$this, 'updateSearchIndex']
        );
    }

    public function updateSearchIndex(Event $event)
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $requestResource = $request->getResource();

        $searchIndexes = $api->search('search_indexes')->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if (in_array($requestResource, $searchIndexSettings['resources'])) {
                $indexer = $searchIndex->indexer();

                if ($request->getOperation() == 'delete') {
                    $id = $request->getId();
                    $indexer->deleteResource($requestResource, $id);
                } else {
                    $resource = $response->getContent();
                    $indexer->indexResource($resource);
                }
            }
        }
    }

    protected function addRoutes()
    {
        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');
        $router = $serviceLocator->get('Router');
        $api = $serviceLocator->get('Omeka\ApiManager');

        if (!$router instanceof \Zend\Router\Http\TreeRouteStack) {
            return;
        }

        $pages = $api->search('search_pages')->getContent();
        foreach ($pages as $page) {
            $path = $page->path();
            $router->addRoute('search-page-' . $page->id(), [
                'type' => 'segment',
                'options' => [
                    'route' => '/s/:site-slug/' . $path,
                    'defaults' => [
                        '__NAMESPACE__' => 'Search\Controller',
                        '__SITE__' => true,
                        'controller' => 'Index',
                        'action' => 'search',
                        'id' => $page->id(),
                    ],
                ],
            ]);
        }
    }
}
