<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\UnifiedDiffPatcher\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class PatchCommand extends \Symfony\Component\Console\Command\Command
{
    protected function configure()
    {
        $this->setName('patch');
        
        $this->addArgument(
            'targets',
            InputArgument::IS_ARRAY,
            'Packages for the patcher to target',
            array()
        );

        $this->addOption(
            '--no-dev',
            null,
            InputOption::VALUE_NONE,
            'Disables installation of require-dev packages'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $diff = new \Vaimo\UnifiedDiffPatcher\Patch\Applier();

            $patchPath = getcwd() . '/test/minor-change.patch';

            chdir('test');

            $p = 0;
            
            $ret = $diff->validatePatch($patchPath, $p, true);

            if ($ret) {
                $diff->processPatch($patchPath, $p);
            } else {
                echo 'Error report:' . PHP_EOL;

                echo implode(PHP_EOL, $diff->getError());
            }
        } catch (Exception $e) {
            echo PHP_EOL . $e->getMessage();
        }

        exit(PHP_EOL);
    }
}
