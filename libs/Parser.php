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

namespace Octris;

use \Octris\Parser\Grammar;

/**
 * General purpose parser.
 *
 * @copyright   copyright (c) 2010-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class Parser
{
    /**
     * Last occured parser error.
     *
     * @var     array
     */
    protected array $last_error = [
        'ifile'   => '',
        'iline'   => 0,
        'line'    => 0,
        'token'   => '',
        'payload' => null
    ];

    /**
     * Instance of grammar class.
     *
     * @var     \Octris\Parser\Grammar
     */
    protected Grammer $grammar;

    /**
     * Tokens to ignore. Tokenizer will drop these tokens.
     *
     * @var     array
     */
    protected array $ignore;

    /**
     * Parser tokens.
     *
     * @var     array
     */
    protected array $tokens;

    /**
     * Token names.
     *
     * @var     array
     */
    protected array $names;

    /**
     * Constructor.
     *
     * @param   \Octris\Parser\Grammar              $grammar            Grammar to use for the parser.
     * @param   array                               $ignore             Optional tokens to ignore.
     */
    public function __construct(Grammar $grammar, array $ignore = [])
    {
        $this->grammar = $grammar;
        $this->ignore = $ignore;
        $this->tokens = $grammar->getTokens();
        $this->names = $grammar->getTokenNames();
    }

    /**
     * Set parser error.
     *
     * @param   string      $ifile      Internal filename the error occured in.
     * @param   int         $iline      Internal line number the error occured in.
     * @param   int         $line       Line in template the error was triggered for.
     * @param   mixed       $token      Token that triggered the error.
     * @param   mixed       $payload    Optional additional information.
     */
    protected function setError(string $ifile, int $iline, int $line, $token, $payload = null): void
    {
        $this->last_error = array(
            'ifile'   => $ifile,
            'iline'   => $iline,
            'line'    => $line,
            'token'   => $token,
            'payload' => $payload
        );
    }

    /**
     * Return instance of grammar as it was specified for constructor.
     *
     * @return  \Octris\Parser\Grammar             Instance of grammar.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Return last occured error.
     *
     * @return  array
     */
    public function getLastError(): array
    {
        return $this->last_error;
    }

    /**
     * Return name of the token or token value, if name could not be resolved.
     *
     * @param   int|string
     * @return  int|string
     */
    public function getTokenName(int|string $token): int|string
    {
        return (isset($this->names[$token])
                ? $this->names[$token]
                : $token);
    }

    /**
     * String tokenizer.
     *
     * @param   string      $in         String to tokenize.
     * @param   int         $line       Optional line offset for error messages.
     * @param   string      $file       Optional name of file to include in token-list.
     * @return  array|bool              Tokens parsed from snippet or false if an error occured.
     */
    public function tokenize(string $in, int $line = 1, string $file = ''): array|bool
    {
        $out = array();
        $mem = $in;

        while (strlen($in) > 0) {
            foreach ($this->tokens as $token => $regexp) {
                if (preg_match('/^(' . $regexp . ')/', $in, $m)) {
                    if (!in_array($token, $this->ignore)) {
                        // collect only tokens not in ignore-list
                        $out[] = array(
                            'token' => $token,
                            'value' => $m[1],
                            'line'  => $line,
                            'file'  => $file
                        );
                    }

                    $in    = substr($in, strlen($m[1]));
                    $line += substr_count($m[1], "\n");
                    continue 2;
                }
            }

            $this->setError(__FILE__, __LINE__, $line, 0, sprintf(
                'parse error %sat "%s" of "%s"',
                ($file != '' ? 'in "' . $file . '" ' : ''),
                $in,
                $mem
            ));

            return false;
        }

        return $out;
    }

    /**
     * Analyze / validate token stream.
     *
     * @param   array               $tokens             Token stream to analyze.
     * @return  bool                                    Returns true if token stream is valid compared to the defined grammar.
     */
    public function analyze(array $tokens): bool
    {
        if (($valid = $this->grammar->analyze($tokens, $error)) === false) {
            $this->setError(
                __FILE__,
                __LINE__,
                $error['line'],
                $error['token'],
                $error['expected']
            );
        }

        return $valid;
    }
}
