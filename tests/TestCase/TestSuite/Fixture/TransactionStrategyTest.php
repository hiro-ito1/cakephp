<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         4.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\TestSuite;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\Fixture\TransactionStrategy;
use Cake\TestSuite\TestCase;

class TransactionStrategyTest extends TestCase
{
    public $fixtures = ['core.Users'];

    /**
     * Test that beforeTest starts a transaction that afterTest closes.
     *
     * @return void
     */
    public function testTransactionWrapping()
    {
        $users = TableRegistry::get('Users');

        $strategy = new TransactionStrategy();
        $strategy->beforeTest(__METHOD__);
        $user = $users->newEntity(['username' => 'testing', 'password' => 'secrets']);

        $users->save($user, ['atomic' => true]);
        $this->assertNotEmpty($users->get($user->id), 'User should exist.');

        // Rollback and the user should be gone.
        $strategy->afterTest(__METHOD__);
        $this->assertNull($users->findById($user->id)->first(), 'No user expected.');
    }
}