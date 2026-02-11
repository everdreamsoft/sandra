<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Events\EventDispatcher;
use SandraCore\Events\EntityEvent;
use SandraCore\EntityFactory;
use SandraCore\Entity;
use SandraCore\Exception\SandraException;

final class EventSystemTest extends SandraTestCase
{
    // --- EventDispatcher unit tests ---

    public function testOnAddsListener(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->on('test', function () {});
        $this->assertTrue($dispatcher->hasListeners('test'));
    }

    public function testHasListenersReturnsFalseWhenNone(): void
    {
        $dispatcher = new EventDispatcher();
        $this->assertFalse($dispatcher->hasListeners('test'));
    }

    public function testDispatchCallsListener(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;
        $dispatcher->on('test', function () use (&$called) {
            $called = true;
        });

        $factory = $this->createFactory('planet', 'solarSystemFile');
        $event = new EntityEvent('test', $factory);
        $dispatcher->dispatch('test', $event);

        $this->assertTrue($called);
    }

    public function testDispatchCallsMultipleListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $count = 0;
        $dispatcher->on('test', function () use (&$count) { $count++; });
        $dispatcher->on('test', function () use (&$count) { $count++; });

        $factory = $this->createFactory('planet', 'solarSystemFile');
        $event = new EntityEvent('test', $factory);
        $dispatcher->dispatch('test', $event);

        $this->assertEquals(2, $count);
    }

    public function testOffRemovesListener(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = function () {};
        $dispatcher->on('test', $listener);
        $dispatcher->off('test', $listener);
        $this->assertFalse($dispatcher->hasListeners('test'));
    }

    public function testStopPropagation(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];
        $dispatcher->on('test', function (EntityEvent $e) use (&$calls) {
            $calls[] = 'first';
            $e->stopPropagation();
        });
        $dispatcher->on('test', function () use (&$calls) {
            $calls[] = 'second';
        });

        $factory = $this->createFactory('planet', 'solarSystemFile');
        $event = new EntityEvent('test', $factory);
        $dispatcher->dispatch('test', $event);

        $this->assertEquals(['first'], $calls);
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testDispatchWithNoListenersReturnsEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $event = new EntityEvent('test', $factory);
        $result = $dispatcher->dispatch('test', $event);
        $this->assertSame($event, $result);
    }

    // --- EntityEvent data access tests ---

    public function testEntityEventAccessors(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'TestPlanet']);
        $data = ['key' => 'value'];

        $event = new EntityEvent('test.event', $factory, $entity, $data);

        $this->assertEquals('test.event', $event->getName());
        $this->assertSame($entity, $event->getEntity());
        $this->assertSame($factory, $event->getFactory());
        $this->assertEquals($data, $event->getData());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testEntityEventWithNullEntity(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $event = new EntityEvent('test', $factory, null);
        $this->assertNull($event->getEntity());
    }

    // --- Integration: entity.creating fires ---

    public function testEntityCreatingEventFires(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $firedData = null;

        $factory->on(EntityEvent::ENTITY_CREATING, function (EntityEvent $e) use (&$firedData) {
            $firedData = $e->getData();
        });

        $factory->createNew(['name' => 'Mars']);

        $this->assertNotNull($firedData);
        $this->assertEquals('Mars', $firedData['data']['name']);
    }

    // --- Integration: entity.created fires with entity ---

    public function testEntityCreatedEventFiresWithEntity(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $firedEntity = null;

        $factory->on(EntityEvent::ENTITY_CREATED, function (EntityEvent $e) use (&$firedEntity) {
            $firedEntity = $e->getEntity();
        });

        $entity = $factory->createNew(['name' => 'Venus']);

        $this->assertNotNull($firedEntity);
        $this->assertSame($entity, $firedEntity);
    }

    // --- Integration: creating cancellation throws SandraException ---

    public function testCreatingCancellationThrowsException(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');

        $factory->on(EntityEvent::ENTITY_CREATING, function (EntityEvent $e) {
            $e->stopPropagation();
        });

        $this->expectException(SandraException::class);
        $factory->createNew(['name' => 'Blocked']);
    }

    // --- Integration: entity.updated fires ---

    public function testEntityUpdatedEventFires(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'Jupiter']);
        $firedEntity = null;

        $factory->on(EntityEvent::ENTITY_UPDATED, function (EntityEvent $e) use (&$firedEntity) {
            $firedEntity = $e->getEntity();
        });

        $factory->update($entity, ['name' => 'Jupiter Updated']);

        $this->assertSame($entity, $firedEntity);
    }

    // --- Integration: brother.linked fires ---

    public function testBrotherLinkedEventFires(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'Earth']);
        $firedData = null;

        $factory->on(EntityEvent::BROTHER_LINKED, function (EntityEvent $e) use (&$firedData) {
            $firedData = $e->getData();
        });

        $entity->setBrotherEntity('orbits', 'sun', null);

        $this->assertNotNull($firedData);
        $this->assertEquals('orbits', $firedData['verb']);
        $this->assertEquals('sun', $firedData['target']);
        $this->assertInstanceOf(Entity::class, $firedData['brotherEntity']);
    }

    // --- Integration: entity.deleted fires ---

    public function testEntityDeletedEventFires(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'Pluto']);
        $deletedEntity = null;

        $factory->on(EntityEvent::ENTITY_DELETED, function (EntityEvent $e) use (&$deletedEntity) {
            $deletedEntity = $e->getEntity();
        });

        $entity->delete();

        $this->assertSame($entity, $deletedEntity);
    }

    // --- Integration: deleting cancellation prevents deletion ---

    public function testDeletingCancellationPreventsDeletion(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'Protected']);
        $deletedFired = false;

        $factory->on(EntityEvent::ENTITY_DELETING, function (EntityEvent $e) {
            $e->stopPropagation();
        });

        $factory->on(EntityEvent::ENTITY_DELETED, function () use (&$deletedFired) {
            $deletedFired = true;
        });

        $entity->delete();

        $this->assertFalse($deletedFired);
    }

    // --- No listeners = no side effects ---

    public function testNoListenersNoSideEffects(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['name' => 'Neptune']);
        $this->assertNotNull($entity);
        $this->assertEquals('Neptune', $entity->get('name'));
    }
}
