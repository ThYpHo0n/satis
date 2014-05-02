<?php

/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\Autodiscover;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Pool;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Repository\ComposerRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\ErrorHandler;
use Symfony\Component\Console\Output\NullOutput;

class Application
{

    protected $config;
    protected $configPath;
    protected $io;
    protected $composer;

    public function __construct($configPath)
    {
        ErrorHandler::register();
        $this->io = new NullIO();
        $file = new JsonFile($configPath);
        if (!$file->exists()) {
            // ToDo: Logging
        }
        $this->config = $file->read();
        $this->configPath = $configPath;
    }

    public function run()
    {
        $this->handleRequest();
    }

    /**
     * ToDo: Implement error handling
     * ToDo: Implement correct version handling (e.g. there exist an entry for this package in Satis
     *       but does not have the correct version)
     * TBD: Implement automatic rebuild of Satis after saved the new Satis config
     */
    private function handleRequest()
    {
        $remoteConfig = file_get_contents('php://input');
        $remoteJson = JsonFile::parseJson($remoteConfig);
        $remoteComposer = $this->getComposer($remoteJson);
        $remotePackages = $this->selectPackages($remoteComposer);
        $satisComposer = $this->getComposer($this->config, true);
        $satisPackages = $this->selectPackages($satisComposer);
        $missingPackages = array_diff($remotePackages, $satisPackages);

        if(sizeof($missingPackages)) {
            foreach($missingPackages as $missingPackage) {
                $this->config['require'][$missingPackage->getName()] = $missingPackage->getVersion();
            }

            file_put_contents($this->configPath, JsonFile::encode($this->config));
            http_response_code(202);
        } else {
            http_response_code(200);
        }

    }

    /**
     * @param null $config
     * @param bool $reset
     *
     * @return Composer
     */
    public function getComposer($config = null, $reset = false)
    {
        if ((null === $this->composer)||($reset)) {
            try {
                $this->composer = Factory::create($this->io, $config);
            } catch (\InvalidArgumentException $e) {
                //ToDo: Implement logging
                exit(1);
            }
        }

        return $this->composer;
    }

    /**
     * @param Composer $composer
     * ToDo: Cleanup and make it faster
     * @return array
     */
    private function selectPackages(Composer $composer)
    {
        $selected = array();

        $repos = $composer->getRepositoryManager()->getRepositories();
        $pool = new Pool('stable');
        foreach ($repos as $repo) {
            try {
                $pool->addRepository($repo);
            } catch(\Exception $exception) {
                //ToDo: Logging
            }
        }

        $links = array_values($composer->getPackage()->getRequires());

        $i = 0;
        while (isset($links[$i])) {
            $link = $links[$i];
            $i++;
            $name = $link->getTarget();
            $matches = $pool->whatProvides($name, $link->getConstraint());

            foreach ($matches as $index => $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                // add matching package if not yet selected
                if (!isset($selected[$package->getName()])) {
                    $selected[$package->getName()] = $package;
                }
            }
        }

        //ksort($selected, SORT_STRING);

        return $selected;
    }
}
