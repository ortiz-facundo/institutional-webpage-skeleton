<?php

declare(strict_types=1);

namespace App\Doctrine\Query\AST\FunctionNode;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL `IFNULL(expr, replacement)` function — registered as a
 * `string_function` in `config/packages/doctrine.yaml`.
 *
 * Renders to native SQL `IFNULL(...)` (MariaDB/MySQL). Useful for
 * bucketing NULL aggregates to a sentinel value at the end of an
 * ORDER BY without needing a CASE WHEN aggregate (which MariaDB
 * rejects in some contexts).
 *
 * Mirrors the DQL signature: two args, both must be scalar
 * expressions. Argument types are NOT enforced at the AST level
 * (we don't have type info for the parameters); the SQL backend
 * is responsible for the runtime check.
 */
final class IfnullFunction extends FunctionNode
{
    private Node $first;
    private Node $second;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER); // IFNULL
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->first = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->second = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'IFNULL('
            . $sqlWalker->walkArithmeticPrimary($this->first)
            . ', '
            . $sqlWalker->walkArithmeticPrimary($this->second)
            . ')';
    }
}
