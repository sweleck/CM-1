<?php
require_once __DIR__ . '/../../TestCase.php';

class CM_LockTest extends TestCase {

	public function testLock() {
		$lock = new CM_Lock('lock-test');
		$this->assertFalse($lock->isLocked());
		$lock->lock(1);
		$this->assertTrue($lock->isLocked());
	}

	public function testUnlock() {
		$lock = new CM_Lock('unlock-test');
		$lock->lock(1);
		$this->assertTrue($lock->isLocked());
		$lock->unlock();
		$this->assertFalse($lock->isLocked());
	}

	public function testWaitUntilUnlocked() {
		$lock = new CM_Lock('wait-test');
		$lockedAt = time();
		$lock->lock(1);
		$lock->waitUntilUnlocked();
		$this->assertSame($lockedAt + 1, time());
	}
}