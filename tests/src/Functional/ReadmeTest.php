<?php

namespace Lullabot\Mpx\Tests\Functional;

use Cache\Adapter\PHPArray\ArrayCachePool;
use GuzzleHttp\Psr7\Uri;
use Lullabot\Mpx\AuthenticatedClient;
use Lullabot\Mpx\Client;
use Lullabot\Mpx\DataService\ByFields;
use Lullabot\Mpx\DataService\DataObjectFactory;
use Lullabot\Mpx\DataService\DataServiceManager;
use Lullabot\Mpx\DataService\Range;
use Lullabot\Mpx\Service\IdentityManagement\User;
use Lullabot\Mpx\Service\IdentityManagement\UserSession;
use Lullabot\Mpx\TokenCachePool;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Tests implementing code shown in documentation.
 */
class ReadmeTest extends FunctionalTestBase
{
    /**
     * This test mirrors the first example in the README.
     */
    public function testExample()
    {
        // Create a new MPX client with the default configuration.
        $defaults = Client::getDefaultConfiguration();
        $client = new Client(new \GuzzleHttp\Client($defaults));

        $user = new User(getenv('MPX_USERNAME'), getenv('MPX_PASSWORD'));
        $store = new FlockStore();
        $tokenCachePool = new TokenCachePool(new ArrayCachePool());
        $session = new UserSession($user, $client, $store, $tokenCachePool);
        $authenticatedClient = new AuthenticatedClient($client, $session);

        // This registers the annotation loader.
        $dataServiceManager = DataServiceManager::basicDiscovery();

        $accountFactory = new DataObjectFactory($dataServiceManager->getDataService('Access Data Service', 'Account', '1.0'), $authenticatedClient);

        $account = $accountFactory->load(new Uri(getenv('MPX_ACCOUNT')))
            ->wait();

        $mediaFactory = new DataObjectFactory($dataServiceManager->getDataService('Media Data Service', 'Media', '1.10'), $authenticatedClient);

        // We query mpx for a media item, while the readme assumes the user knows an ID already.
        $filter = new ByFields();
        $range = new Range();
        $range->setStartIndex(1)
            ->setEndIndex(1);
        $filter->setRange($range);
        $results = $mediaFactory->select($filter, $account);
        foreach ($results as $media) {
            // Replace the ID to the media item to load. You can find it under "History -> ID" in the MPX console.
            $id = $media->getId();
            $media = $mediaFactory->load($id)
                ->wait();
            $this->assertEquals($id, $media->getId());
            break;
        }
    }
}
