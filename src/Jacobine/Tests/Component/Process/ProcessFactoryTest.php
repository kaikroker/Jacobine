<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Tests\Component\Process;

use Jacobine\Component\Process\ProcessFactory;

/**
 * Class ProcessFactoryTest
 *
 * Unit test class for \Jacobine\Component\Process\ProcessFactory
 *
 * @package Jacobine\Tests\Component\Process
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class ProcessFactoryTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var \Jacobine\Component\Process\ProcessFactory
     */
    protected $factory;

    public function setUp()
    {
        $this->factory = new ProcessFactory();
    }

    public function testCreateProcessWithCommand()
    {
        $command = 'ls -al';

        $process = $this->factory->createProcess($command);

        $this->assertInstanceOf('\Symfony\Component\Process\Process', $process);
        $this->assertSame($command, $process->getCommandLine());
    }

    public function testCreateProcessWithEmptyCommand()
    {
        $command = '';

        $process = $this->factory->createProcess($command);

        $this->assertInstanceOf('\Symfony\Component\Process\Process', $process);
        $this->assertSame($command, $process->getCommandLine());
    }
}
