<?php

/**
 * @file
 * Contains Mpx\User.
 */

namespace Mpx;

use Pimple\Container;
use Psr\Log\LoggerInterface;
use Stash\Interfaces\PoolInterface;

class User implements UserInterface {
  use HasCachePoolTrait;
  use HasClientTrait;
  use HasLoggerTrait;

  /**
   * @var string
   */
  private $username;

  /**
   * @var string
   */
  private $password;

  /** @var \Stash\Interfaces\ItemInterface */
  private $tokenCache;

  /**
   * @param string $username
   * @param string $password
   * @param \Mpx\ClientInterface $client
   * @param \Stash\Interfaces\PoolInterface $cache
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function __construct($username, $password, ClientInterface $client = NULL, PoolInterface $cache = NULL, LoggerInterface $logger = NULL) {
    $this->username = $username;
    $this->password = $password;
    $this->client = $client;
    $this->cachePool = $cache;
    $this->logger = $logger;

    $this->tokenCache = $this->getCachePool()->getItem('token:' . $this->getUsername());
  }

  /**
   * @param string $username
   * @param string $password
   * @param \Pimple\Container $container
   *
   * @return static
   */
  public static function create($username, $password, Container $container) {
    return new static(
      $username,
      $password,
      $container['client'],
      $container['cache'],
      $container['logger']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getUsername() {
    return $this->username;
  }

  /**
   * {@inheritdoc}
   */
  public function getPassword() {
    return $this->password;
  }

  /**
   * {@inheritdoc}
   */
  public function acquireToken($duration = NULL, $force = FALSE) {
    $token = $this->tokenCache->get();

    if ($force || !$token || $this->tokenCache->isMiss() || $this->tokenCache->getExpiration()->getTimestamp() <= (time() + $duration)) {
      if ($token) {
        $this->signOut();
      }
      $token = $this->signIn();
    }

    return $token;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateToken() {
    $this->tokenCache->clear();
  }

  /**
   * {@inheritdoc}
   */
  public function signIn($duration = NULL) {
    // @todo Do we need to lock $this->tokenCache?

    $options['auth'] = array($this->getUsername(), $this->getPassword());
    $options['query'] = array(
      'schema' => '1.0',
      'form' => 'json',
    );

    if (!empty($duration)) {
      // API expects this value in milliseconds, not seconds.
      $options['query']['_duration'] = $duration * 1000;
      $options['query']['_idleTimeout'] = $duration * 1000;
    }

    $time = time();
    $data = $this->getClient()->get(
      'https://identity.auth.theplatform.com/idm/web/Authentication/signIn',
      $options
    );
    $token = $data['signInResponse']['token'];
    $lifetime = floor(min($data['signInResponse']['duration'], $data['signInResponse']['idleTimeout']) / 1000);

    $this->getLogger()->info(
      'Fetched new mpx token {token} for user {username} that expires on {date}.',
      array(
        'token' => $token,
        'username' => $this->getUsername(),
        'date' => date(DATE_ISO8601, $time + $lifetime),
      )
    );

    // Save the token to the cache and return it.
    $this->tokenCache->set($token, $lifetime);

    return $token;
  }

  /**
   * {@inheritdoc}
   */
  public function signOut() {
    $token = $this->tokenCache->get();

    if ($token && !$this->tokenCache->isMiss()) {
      $this->getClient()->get(
        'https://identity.auth.theplatform.com/idm/web/Authentication/signOut',
        array(
          'query' => array(
            'schema' => '1.0',
            'form' => 'json',
            '_token' => $token,
          ),
        )
      );

      $this->getLogger()->info(
        'Expired mpx authentication token {token} for {username}.',
        array(
          'token' => $token,
          'username' => $this->getUsername(),
        )
      );
    }

    $this->tokenCache->clear();
  }

  /**
   * {@inheritdoc}
   */
  public function getSelfId() {
    $item = $this->getCachePool()->getItem('user:' . $this->getUsername());
    $data = $item->get();
    if ($item->isMiss()) {
      $data = $this->getClient()->authenticatedRequest(
        'GET',
        'https://identity.auth.theplatform.com/idm/web/Self/getSelfId',
        $this,
        array(
          'query' => array(
            'schema' => '1.0',
            'form' => 'json',
          ),
        )
      );
      $data = $data['getSelfIdResponse'];
      $item->set($data);
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    $data = $this->getSelfId();
    return basename($data['userId']);
  }

}
