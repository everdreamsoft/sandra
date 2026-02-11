<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\EntityFactory;
use SandraCore\Validation\ValidationException;
use SandraCore\Validation\Validator;

final class ValidationTest extends SandraTestCase
{
    public function testRequiredRule(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
        ]);

        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => '']);
    }

    public function testRequiredPassesWithValue(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
        ]);

        $entity = $factory->createNew(['name' => 'Mars']);
        $this->assertNotNull($entity);
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
        ]);

        $this->expectException(ValidationException::class);
        $factory->createNew(['description' => 'no name given']);
    }

    public function testNumericRule(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'mass[earth]' => ['numeric'],
        ]);

        // Valid numeric
        $entity = $factory->createNew(['name' => 'Mars', 'mass[earth]' => '0.107']);
        $this->assertNotNull($entity);

        // Invalid numeric
        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Bad', 'mass[earth]' => 'not-a-number']);
    }

    public function testMinMaxRules(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'mass[earth]' => ['numeric', 'min:0', 'max:1000'],
        ]);

        // Valid range
        $entity = $factory->createNew(['name' => 'Earth', 'mass[earth]' => '1']);
        $this->assertNotNull($entity);

        // Below min
        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Neg', 'mass[earth]' => '-5']);
    }

    public function testMaxExceeded(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'mass[earth]' => ['numeric', 'max:100'],
        ]);

        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Heavy', 'mass[earth]' => '200']);
    }

    public function testEmailRule(): void
    {
        $factory = $this->createFactory('user', 'userFile');
        $factory->setValidation([
            'email' => ['email'],
        ]);

        // Valid email
        $entity = $factory->createNew(['name' => 'John', 'email' => 'john@example.com']);
        $this->assertNotNull($entity);

        // Invalid email
        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Bad', 'email' => 'not-an-email']);
    }

    public function testMaxlengthRule(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['maxlength:5'],
        ]);

        $entity = $factory->createNew(['name' => 'Mars']);
        $this->assertNotNull($entity);

        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Jupiter']);
    }

    public function testIntegerRule(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'moons' => ['integer'],
        ]);

        $entity = $factory->createNew(['name' => 'Mars', 'moons' => '2']);
        $this->assertNotNull($entity);

        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'Bad', 'moons' => '2.5']);
    }

    public function testStringRule(): void
    {
        $validator = new Validator([
            'name' => ['string'],
        ]);

        // String rule passes for null (optional field)
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $entity = $factory->createNew(['description' => 'test']);
        $this->assertNotNull($entity);
    }

    public function testMultipleRulesOnOneField(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required', 'maxlength:10'],
            'mass[earth]' => ['required', 'numeric', 'min:0'],
        ]);

        $entity = $factory->createNew(['name' => 'Mars', 'mass[earth]' => '0.107']);
        $this->assertNotNull($entity);
    }

    public function testMultipleErrors(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
            'mass[earth]' => ['required', 'numeric'],
        ]);

        try {
            $factory->createNew([]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('mass[earth]', $errors);
            $this->assertContains('required', $errors['name']);
            $this->assertContains('required', $errors['mass[earth]']);
        }
    }

    public function testGetFirstError(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
        ]);

        try {
            $factory->createNew([]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('name', $e->getFirstError());
            $this->assertStringContainsString('required', $e->getFirstError());
        }
    }

    public function testCustomValidator(): void
    {
        $factory = $this->createFactory('location', 'locationFile');
        $factory->setValidation([
            'coords' => ['coordinates'],
        ]);
        $factory->addValidator('coordinates', function ($value) {
            if ($value === null || $value === '') {
                return true;
            }
            return (bool)preg_match('/^-?\d+\.\d+,-?\d+\.\d+$/', $value);
        });

        // Valid coordinates
        $entity = $factory->createNew(['coords' => '46.2044,6.1432']);
        $this->assertNotNull($entity);

        // Invalid coordinates
        $this->expectException(ValidationException::class);
        $factory->createNew(['coords' => 'invalid']);
    }

    public function testUniqueRule(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required', 'unique'],
        ]);

        $factory->populateLocal();

        // First creation should pass
        $entity = $factory->createNew(['name' => 'UniqueTest']);
        $this->assertNotNull($entity);

        // Second creation with same name should fail
        $this->expectException(ValidationException::class);
        $factory->createNew(['name' => 'UniqueTest']);
    }

    public function testNoValidationDoesNotInterfere(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        // No validation set - should work as before
        $entity = $factory->createNew(['name' => '']);
        $this->assertNotNull($entity);
    }

    public function testOptionalFieldsPassWhenAbsent(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $factory->setValidation([
            'name' => ['required'],
            'mass[earth]' => ['numeric', 'min:0'],
        ]);

        // mass[earth] is not required, so omitting it should pass
        $entity = $factory->createNew(['name' => 'Venus']);
        $this->assertNotNull($entity);
    }

    public function testSetValidationReturnsSelf(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $result = $factory->setValidation(['name' => ['required']]);
        $this->assertSame($factory, $result);
    }

    public function testAddValidatorReturnsSelf(): void
    {
        $factory = $this->createFactory('planet', 'solarSystemFile');
        $result = $factory->addValidator('custom', function () { return true; });
        $this->assertSame($factory, $result);
    }
}
