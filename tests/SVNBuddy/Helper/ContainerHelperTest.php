<?php
/**
 * This file is part of the SVN-Buddy library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/aik099/svn-buddy
 */

namespace Tests\aik099\SVNBuddy\Helper;


use aik099\SVNBuddy\Helper\ContainerHelper;
use Pimple\Container;

class ContainerHelperTest extends \PHPUnit_Framework_TestCase
{

	/**
	 * Container helper
	 *
	 * @var ContainerHelper
	 */
	protected $containerHelper;

	/**
	 * Pimple container.
	 *
	 * @var Container
	 */
	protected $container;

	protected function setUp()
	{
		parent::setUp();

		$this->container = $this->prophesize('Pimple\\Container')->reveal();
		$this->containerHelper = new ContainerHelper($this->container);
	}

	public function testGetContainer()
	{
		$this->assertSame($this->container, $this->containerHelper->getContainer());
	}

	public function testGetName()
	{
		$this->assertEquals('container', $this->containerHelper->getName());
	}

}