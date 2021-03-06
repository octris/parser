<?php

declare(strict_types=1);

/*
 * This file is part of the 'octris/parser' package.
 *
 * (c) Harald Lapp <harald@octris.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Octris\Parser;

use Octris\Parser\Exception;

/**
 * Class for defining a parser grammar.
 *
 * @copyright   copyright (c) 2014-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class Grammar
{
    /**
     * Unknown token.
     */
    public const T_UNKNOWN = 0;

    /**
     * ID of initial rule.
     *
     * @var     int|string|null
     */
    protected int|string|null $initial = null;

    /**
     * Grammar rules.
     *
     * @var     array
     */
    protected array $rules = [];

    /**
     * Events for tokens.
     *
     * @var     array
     */
    protected array $events = [];

    /**
     * Registered tokens.
     *
     * @var     array
     */
    protected array $tokens = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Add a rule to the grammar.
     *
     * @param   int|string          $id                 Token identifier to apply the rule for.
     * @param   array               $rule               Grammar rule.
     * @param   bool                $initial            Whether to set the rule as initial.
     * @throws  UnexpectedValueException
     */
    public function addRule(int|string $id, array $rule, bool $initial = false): void
    {
        if (isset($this->rules[$id])) {
            throw new Exception\UnexpectedValueException('Rule is already defined!');
        }

        // { validate
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($rule),
            true
        );

        foreach ($iterator as $k => $v) {
            if (!is_int($k)) {
                if (!is_array($v)) {
                    throw new Exception\UnexpectedValueException(sprintf("No array specified for rule operator '%s'", $k));
                }

                switch ($k) {
                    case '$option':
                        if (($cnt = count($v)) != 1) {
                            throw new Exception\UnexpectedValueException(sprintf("Rule operator '\$option' takes exactly one item, '%d' given", $cnt));
                        }
                        break;
                    case '$alternation':
                    case '$concatenation':
                    case '$repeat':
                        break;
                    default:
                        throw new Exception\UnexpectedValueException(sprintf("Invalid rule operator '%s'", $k));
                }
            }
        }
        // }

        $this->rules[$id] = $rule;

        if ($initial) {
            $this->initial = $id;
        }
    }

    /**
     * Add an event for a token.
     *
     * @param   int                 $id                 Token identifier.
     * @param   callable            $cb                 Callback to call if the token occurs.
     */
    public function addEvent(int $id, callable $cb): void
    {
        if (!isset($this->events[$id])) {
            $this->events[$id] = [];
        }

        $this->events[$id][] = $cb;
    }

    /**
     * Return list of defined tokens.
     *
     * @return  array                                   Defined tokens.
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * Return names of tokens. Will only work, if tokens are defined using class 'constants'.
     *
     * @return  array                                   Names of defined tokens.
     */
    public function getTokenNames(): array
    {
        return array_flip((new \ReflectionClass($this))->getConstants());
    }

    /**
     * Add a token to the registry.
     *
     * @param   string          $name                   Name of token.
     * @param   string          $regexp                 Regular expression for parser to match token.
     */
    public function addToken(string $name, string $regexp): void
    {
        $this->tokens[$name] = $regexp;
    }

    /**
     * Return the EBNF for the defined grammar.
     *
     * @return  string                                  The EBNF.
     */
    public function getEBNF(): string
    {
        $glue = array(
            '$concatenation' => array('', ' , ', ''),
            '$alternation'   => array('( ', ' | ', ' )'),
            '$repeat'        => array('{ ', '', ' }'),
            '$option'        => array('[ ', '', ' ]')
        );

        $render = function ($rule) use ($glue, &$render) {
            if (is_array($rule)) {
                $type = key($rule);

                foreach ($rule[$type] as &$_rule) {
                    $_rule = $render($_rule);
                }

                $return = $glue[$type][0] .
                          implode($glue[$type][1], $rule[$type]) .
                          $glue[$type][2];
            } else {
                $return = $rule;
            }

            return $return;
        };

        $return = '';

        foreach ($this->rules as $name => $rule) {
            $return .= $name . ' = ' . $render($rule) . " ;\n";
        }

        return $return;
    }

    /**
     * Analyze / validate token stream. If the token stream is invalid, the second, optional, parameter
     * will contain the expected token.
     *
     * @param   array               $tokens             Token stream to analyze.
     * @param   array               &$error             If an error occured the variable get's filled with the current token information and expected token(s).
     * @return  bool                                    Returns true if token stream is valid compared to the defined grammar.
     */
    public function analyze(array $tokens, array &$error = null): bool
    {
        $expected = [];
        $pos      = 0;
        $error    = false;

        $v = function ($rule) use ($tokens, &$pos, &$v, &$expected, &$error) {
            $valid = false;

            if (is_scalar($rule) && isset($this->rules[$rule])) {
                // import rule
                $rule = $this->rules[$rule];
            }

            if (is_array($rule)) {
                $type = key($rule);

                switch ($type) {
                    case '$concatenation':
                        $state = $pos;

                        foreach ($rule[$type] as $_rule) {
                            if (!($valid = $v($_rule))) {
                                if ($error) {
                                    return false;
                                } elseif (($pos - $state) > 0) {
                                    $error = (isset($tokens[$pos])
                                              ? $tokens[$pos]
                                              : array_merge(
                                                  $tokens[$pos - 1],
                                                  array(
                                                      'token' => self::T_UNKNOWN,
                                                      'value' => self::T_UNKNOWN
                                                  )
                                              ));

                                    $error['expected'] = array_unique($expected);
                                    return false;
                                }
                                break;
                            }
                        }

                        if (!$valid) {
                            // rule did not match, restore position in token stream
                            $pos   = $state;
                            $valid = false;
                        }
                        break;
                    case '$alternation':
                        $state = $pos;

                        foreach ($rule[$type] as $_rule) {
                            if (($valid = $v($_rule)) || $error) {
                                // if ($error) return false;
                                break;
                            }
                        }

                        if (!$valid) {
                            // rule did not match, restore position in token stream
                            $pos   = $state;
                            $valid = false;
                        }
                        break;
                    case '$option':
                        $state = $pos;

                        foreach ($rule[$type] as $_rule) {
                            if (($valid = $v($_rule)) || $error) {
                                break;
                            }
                        }

                        if (!$valid) {
                            // rule did not match, restore position in token stream
                            $error = false; // optional rule, do not produce an error
                            $pos   = $state;
                            $valid = true;
                        }
                        break;
                    case '$repeat':
                        do {
                            $state = $pos;

                            foreach ($rule[$type] as $_rule) {
                                if (($valid = $v($_rule)) || $error) {
                                    if ($error) {
                                        return false;
                                    }
                                    break;
                                }
                            }
                        } while ($valid);

                        if (!$valid) {
                            // rule did not match, restore position in token stream
                            $pos   = $state;
                            $valid = true;
                        }
                        break;
                }
            } elseif (($valid = isset($tokens[$pos]))) {
                $token = $tokens[$pos];

                if (($valid = ($token['token'] == $rule))) {
                    ++$pos;
                    $expected = [];
                } else {
                    $expected[] = $rule;
                }
            } else {
                $expected[] = $rule;
            }

            return (!$error ? $valid : false);
        };

        if (!is_null($this->initial)) {
            $valid = $v($this->rules[$this->initial]);
        } else {
            // no initial rule, build one
            $valid = $v(['$alternation' => array_keys($this->rules)]);
        }

        $valid = (!$error && $valid && $pos == count($tokens));

        if ($valid) {
            foreach ($tokens as $token) {
                if (isset($this->events[$token['token']])) {
                    foreach ($this->events[$token['token']] as $event) {
                        $event($token);
                    }
                }
            }
        } elseif (!$error) {
            $error = $tokens[$pos];
            $error['expected'] = $expected;
        }

        return $valid;
    }
}
