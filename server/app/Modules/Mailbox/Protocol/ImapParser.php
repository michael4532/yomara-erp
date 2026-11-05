<?php

namespace App\Modules\Mailbox\Protocol;

/** Analyseur des réponses IMAP : atomes, chaînes entre guillemets, littéraux {n}, NIL, listes imbriquées. */
class ImapParser
{
    protected int $i = 0;

    protected int $n;

    public function __construct(protected string $s)
    {
        $this->n = strlen($s);
    }

    public function next(): mixed
    {
        $this->skipSpaces();
        if ($this->i >= $this->n) {
            return null;
        }
        $c = $this->s[$this->i];
        if ($c === '(') {
            $this->i++;
            $out = [];
            while (true) {
                $this->skipSpaces();
                if ($this->i >= $this->n) {
                    return $out;
                }
                if ($this->s[$this->i] === ')') {
                    $this->i++;

                    return $out;
                }
                $out[] = $this->next();
            }
        }
        if ($c === '"') {
            $this->i++;
            $out = '';
            while ($this->i < $this->n) {
                $ch = $this->s[$this->i++];
                if ($ch === '\\' && $this->i < $this->n) {
                    $out .= $this->s[$this->i++];
                } elseif ($ch === '"') {
                    break;
                } else {
                    $out .= $ch;
                }
            }

            return $out;
        }
        if ($c === '{') {
            $end = strpos($this->s, '}', $this->i);
            $len = (int) substr($this->s, $this->i + 1, $end - $this->i - 1);
            $this->i = $end + 1;
            if (substr($this->s, $this->i, 2) === "\r\n") {
                $this->i += 2;
            }
            $out = substr($this->s, $this->i, $len);
            $this->i += $len;

            return $out;
        }
        // atome : jusqu'à espace ou parenthèse ; les crochets BODY[...] sont gardés entiers
        $start = $this->i;
        $depth = 0;
        while ($this->i < $this->n) {
            $ch = $this->s[$this->i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
            } elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')')) {
                break;
            }
            $this->i++;
        }
        $atom = substr($this->s, $start, $this->i - $start);

        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    protected function skipSpaces(): void
    {
        while ($this->i < $this->n && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\r" || $this->s[$this->i] === "\n")) {
            $this->i++;
        }
    }
}
