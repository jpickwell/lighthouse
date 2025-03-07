<?php

namespace Tests\Unit\Schema;

use Closure;
use GraphQL\Language\Parser;
use Nuwave\Lighthouse\Exceptions\DirectiveException;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Schema\Directives\FieldDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use ReflectionProperty;
use Tests\TestCase;

class DirectiveLocatorTest extends TestCase
{
    /**
     * @var \Nuwave\Lighthouse\Schema\DirectiveLocator
     */
    protected $directiveLocator;

    public function setUp(): void
    {
        parent::setUp();

        $this->directiveLocator = $this->app->make(DirectiveLocator::class);
    }

    public function testRegistersLighthouseDirectives(): void
    {
        $this->assertInstanceOf(
            FieldDirective::class,
            $this->directiveLocator->create('field')
        );
    }

    public function testHydratesBaseDirectives(): void
    {
        $fieldDefinition = Parser::fieldDefinition(/** @lang GraphQL */ '
            foo: String @field
        ');

        /** @var \Nuwave\Lighthouse\Schema\Directives\FieldDirective $fieldDirective */
        $fieldDirective = $this
            ->directiveLocator
            ->associated($fieldDefinition)
            ->first();

        $definitionNode = new ReflectionProperty($fieldDirective, 'definitionNode');
        $definitionNode->setAccessible(true);

        $this->assertSame(
            $fieldDefinition,
            $definitionNode->getValue($fieldDirective)
        );
    }

    public function testSkipsHydrationForNonBaseDirectives(): void
    {
        $fieldDefinition = Parser::fieldDefinition(/** @lang GraphQL */ '
            foo: String @foo
        ');

        $directive = new class() implements FieldMiddleware {
            public static function definition(): string
            {
                return /** @lang GraphQL */ 'foo';
            }

            public function handleField(FieldValue $fieldValue, Closure $next): FieldValue
            {
                return $fieldValue;
            }
        };

        $this->directiveLocator->setResolved('foo', get_class($directive));

        $directive = $this
            ->directiveLocator
            ->associated($fieldDefinition)
            ->first();

        $this->assertObjectNotHasAttribute('definitionNode', $directive);
    }

    public function testThrowsIfDirectiveNameCanNotBeResolved(): void
    {
        $this->expectException(DirectiveException::class);

        $this->directiveLocator->create('bar');
    }

    public function testCreateSingleDirective(): void
    {
        $fieldDefinition = Parser::fieldDefinition(/** @lang GraphQL */ '
            foo: [Foo!]! @hasMany
        ');

        $resolver = $this->directiveLocator->exclusiveOfType($fieldDefinition, FieldResolver::class);
        $this->assertInstanceOf(FieldResolver::class, $resolver);
    }

    public function testThrowsExceptionWhenMultipleFieldResolverDirectives(): void
    {
        $this->expectException(DirectiveException::class);
        $this->expectExceptionMessage("Node bar can only have one directive of type Nuwave\Lighthouse\Support\Contracts\FieldResolver but found [@hasMany, @belongsTo].");

        $fieldDefinition = Parser::fieldDefinition(/** @lang GraphQL */ '
            bar: [Bar!]! @hasMany @belongsTo
        ');

        $this->directiveLocator->exclusiveOfType($fieldDefinition, FieldResolver::class);
    }

    public function testCreateMultipleDirectives(): void
    {
        $fieldDefinition = Parser::fieldDefinition(/** @lang GraphQL */ '
            bar: String @can(if: ["viewBar"]) @event
        ');

        $middleware = $this->directiveLocator->associatedOfType($fieldDefinition, FieldMiddleware::class);
        $this->assertCount(2, $middleware);
    }
}
