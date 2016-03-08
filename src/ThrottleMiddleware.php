<?php
/**
 * Created by PhpStorm.
 * User: Glenn
 * Date: 2016-03-08
 * Time: 2:38 PM
 */

namespace Geggleto\Middleware;


use Ejsmont\CircuitBreaker\Storage\Adapter\ApcAdapter;
use Ejsmont\CircuitBreaker\Storage\Adapter\MemcachedAdapter;
use Interop\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ThrottleMiddleware
{
    protected static $headers = [
        'Forwarded',
        'Forwarded-For',
        'Client-Ip',
        'X-Forwarded',
        'X-Forwarded-For',
        'X-Cluster-Client-Ip',
    ];

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var ApcAdapter
     */
    protected $storage;

    /**
     * @var int
     */
    protected $perSecond;

    /**
     * ThrottleMiddleware constructor.
     * @param ContainerInterface $containerInterface
     */
    public function __construct(ContainerInterface $containerInterface, $perSecond = 60)
    {
        $this->container = $containerInterface;
        if (!$this->container->has("adapter")) {
            throw new \InvalidArgumentException("Container is missing ApcAdapter");
        }

        $this->storage = $containerInterface['apc'];

        $this->perSecond = (int)$perSecond;
    }

    /**
     * Registers an APC Adapter in a Container object
     * @param ContainerInterface $containerInterface
     */
    public static function registerApc(ContainerInterface $containerInterface) {
        $containerInterface["adapter"] = function ($c) {
            return new ApcAdapter();
        };
    }

    /**
     * Registers a Memcached Instance in the container
     * @param \Memcached $memcached
     * @param ContainerInterface $containerInterface
     */
    public static function registerMemcached(\Memcached $memcached, ContainerInterface $containerInterface) {
        $containerInterface["adapter"] = function ($c) use ($memcached) {
            return new MemcachedAdapter($memcached);
        };
    }

    /**
     * @param ServerRequestInterface $requestInterface
     * @param ResponseInterface $responseInterface
     * @param callable $next
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $requestInterface, ResponseInterface $responseInterface, callable $next)
    {
        //Get IPs
        $ips = self::getIpsOfRequest($requestInterface);

        $block = false;

        foreach ($ips as $ip) {
            $count = intval($this->storage->loadStatus('throttle', $ip));


            $lastTestBeforeNow = $this->storage->loadStatus('throttle', $ip.'lastTest');
            //how many requests can we knock off.

            $timeNow = time();

            $timeDiff = abs(round(($timeNow - $lastTestBeforeNow) / 60)) * $this->perSecond;

            $count = $count + 1 - $timeDiff;

            $this->storage->saveStatus('throttle', $ip, $count);
            $this->storage->saveStatus('throttle', $ip.'lastTest', $timeNow, true);

            if ($count > $this->perSecond) {
                $block = true;
            }
        }

        if ($block) {
            return $responseInterface->withStatus(429);
        } else {
            return $next($requestInterface, $responseInterface);
        }
    }


    public static function getIpsOfRequest(ServerRequestInterface $requestInterface) {
        $ips = array();

        foreach (ThrottleMiddleware::$headers as $name) {
            $header = $requestInterface->getHeaderLine($name);
            if (!empty($header)) {
                foreach (array_map('trim', explode(',', $header)) as $ip) {
                    if (!in_array($ip, $ips)) {
                        $i = filter_var($ip, FILTER_VALIDATE_IP);
                        if ($i) {
                            $ips[] = $i;
                        }
                    }
                }
            }
        }

        return $ips;
    }
}