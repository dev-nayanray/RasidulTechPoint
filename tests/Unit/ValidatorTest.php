<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Support\Validator;
use Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredRejectsMissingValues(): void
    {
        $this->assertThrows(ValidationException::class, function () {
            Validator::make(['name' => ''], ['name' => 'required|string'])->validate();
        }, 'empty string should fail required');
    }

    public function testNullableOptionalFieldsPass(): void
    {
        $data = Validator::make(['name' => 'x', 'phone' => ''], [
            'name' => 'required|string',
            'phone' => 'nullable|string|max:30',
        ])->validate();
        $this->assertTrue(array_key_exists('phone', $data));
    }

    public function testEmailFormat(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['email' => 'not-an-email'], ['email' => 'required|email'])->validate());
        Validator::make(['email' => 'a@b.co'], ['email' => 'required|email'])->validate();
        $this->assertTrue(true);
    }

    public function testConfirmedRule(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['password' => 'abcdefghij', 'password_confirmation' => 'xyz'], [
                'password' => 'required|confirmed',
            ])->validate());
    }

    public function testDecimalRule(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['amount' => '12.345'], ['amount' => 'required|decimal:2'])->validate());
        Validator::make(['amount' => '12.34'], ['amount' => 'required|decimal:2'])->validate();
        $this->assertTrue(true);
    }

    public function testInRule(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['status' => 'hacked'], ['status' => 'required|in:A,B,C,D'])->validate());
    }

    public function testUniqueRuleHitsDatabase(): void
    {
        $this->makeUser('unique-check@test.local');
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['email' => 'unique-check@test.local'], ['email' => 'required|unique:users,email'])->validate());
        // different value passes
        Validator::make(['email' => 'free@test.local'], ['email' => 'required|unique:users,email'])->validate();
        $this->assertTrue(true);
    }

    public function testUniqueRuleIgnoresId(): void
    {
        $id = $this->makeUser('self-check@test.local');
        Validator::make(['email' => 'self-check@test.local'], ["email" => "required|unique:users,email,{$id}"])->validate();
        $this->assertTrue(true);
    }

    public function testMinLength(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['password' => 'short'], ['password' => 'required|min:10'])->validate());
    }

    public function testSlugRule(): void
    {
        $this->assertThrows(ValidationException::class, fn () =>
            Validator::make(['slug' => 'Not A Slug'], ['slug' => 'required|slug'])->validate());
        Validator::make(['slug' => 'valid-slug-1'], ['slug' => 'required|slug'])->validate();
        $this->assertTrue(true);
    }
}
