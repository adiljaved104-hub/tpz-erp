<?php

namespace App\Support;

final class ArabicPdfText
{
    /** @var array<string, array{isolated: int, final: int|null, initial: int|null, medial: int|null, before: bool, after: bool}> */
    private const FORMS = [
        'ء' => ['isolated' => 0xFE80, 'final' => null, 'initial' => null, 'medial' => null, 'before' => false, 'after' => false],
        'آ' => ['isolated' => 0xFE81, 'final' => 0xFE82, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'أ' => ['isolated' => 0xFE83, 'final' => 0xFE84, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ؤ' => ['isolated' => 0xFE85, 'final' => 0xFE86, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'إ' => ['isolated' => 0xFE87, 'final' => 0xFE88, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ئ' => ['isolated' => 0xFE89, 'final' => 0xFE8A, 'initial' => 0xFE8B, 'medial' => 0xFE8C, 'before' => true, 'after' => true],
        'ا' => ['isolated' => 0xFE8D, 'final' => 0xFE8E, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ب' => ['isolated' => 0xFE8F, 'final' => 0xFE90, 'initial' => 0xFE91, 'medial' => 0xFE92, 'before' => true, 'after' => true],
        'ة' => ['isolated' => 0xFE93, 'final' => 0xFE94, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ت' => ['isolated' => 0xFE95, 'final' => 0xFE96, 'initial' => 0xFE97, 'medial' => 0xFE98, 'before' => true, 'after' => true],
        'ث' => ['isolated' => 0xFE99, 'final' => 0xFE9A, 'initial' => 0xFE9B, 'medial' => 0xFE9C, 'before' => true, 'after' => true],
        'ج' => ['isolated' => 0xFE9D, 'final' => 0xFE9E, 'initial' => 0xFE9F, 'medial' => 0xFEA0, 'before' => true, 'after' => true],
        'ح' => ['isolated' => 0xFEA1, 'final' => 0xFEA2, 'initial' => 0xFEA3, 'medial' => 0xFEA4, 'before' => true, 'after' => true],
        'خ' => ['isolated' => 0xFEA5, 'final' => 0xFEA6, 'initial' => 0xFEA7, 'medial' => 0xFEA8, 'before' => true, 'after' => true],
        'د' => ['isolated' => 0xFEA9, 'final' => 0xFEAA, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ذ' => ['isolated' => 0xFEAB, 'final' => 0xFEAC, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ر' => ['isolated' => 0xFEAD, 'final' => 0xFEAE, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ز' => ['isolated' => 0xFEAF, 'final' => 0xFEB0, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'س' => ['isolated' => 0xFEB1, 'final' => 0xFEB2, 'initial' => 0xFEB3, 'medial' => 0xFEB4, 'before' => true, 'after' => true],
        'ش' => ['isolated' => 0xFEB5, 'final' => 0xFEB6, 'initial' => 0xFEB7, 'medial' => 0xFEB8, 'before' => true, 'after' => true],
        'ص' => ['isolated' => 0xFEB9, 'final' => 0xFEBA, 'initial' => 0xFEBB, 'medial' => 0xFEBC, 'before' => true, 'after' => true],
        'ض' => ['isolated' => 0xFEBD, 'final' => 0xFEBE, 'initial' => 0xFEBF, 'medial' => 0xFEC0, 'before' => true, 'after' => true],
        'ط' => ['isolated' => 0xFEC1, 'final' => 0xFEC2, 'initial' => 0xFEC3, 'medial' => 0xFEC4, 'before' => true, 'after' => true],
        'ظ' => ['isolated' => 0xFEC5, 'final' => 0xFEC6, 'initial' => 0xFEC7, 'medial' => 0xFEC8, 'before' => true, 'after' => true],
        'ع' => ['isolated' => 0xFEC9, 'final' => 0xFECA, 'initial' => 0xFECB, 'medial' => 0xFECC, 'before' => true, 'after' => true],
        'غ' => ['isolated' => 0xFECD, 'final' => 0xFECE, 'initial' => 0xFECF, 'medial' => 0xFED0, 'before' => true, 'after' => true],
        'ف' => ['isolated' => 0xFED1, 'final' => 0xFED2, 'initial' => 0xFED3, 'medial' => 0xFED4, 'before' => true, 'after' => true],
        'ق' => ['isolated' => 0xFED5, 'final' => 0xFED6, 'initial' => 0xFED7, 'medial' => 0xFED8, 'before' => true, 'after' => true],
        'ك' => ['isolated' => 0xFED9, 'final' => 0xFEDA, 'initial' => 0xFEDB, 'medial' => 0xFEDC, 'before' => true, 'after' => true],
        'ل' => ['isolated' => 0xFEDD, 'final' => 0xFEDE, 'initial' => 0xFEDF, 'medial' => 0xFEE0, 'before' => true, 'after' => true],
        'م' => ['isolated' => 0xFEE1, 'final' => 0xFEE2, 'initial' => 0xFEE3, 'medial' => 0xFEE4, 'before' => true, 'after' => true],
        'ن' => ['isolated' => 0xFEE5, 'final' => 0xFEE6, 'initial' => 0xFEE7, 'medial' => 0xFEE8, 'before' => true, 'after' => true],
        'ه' => ['isolated' => 0xFEE9, 'final' => 0xFEEA, 'initial' => 0xFEEB, 'medial' => 0xFEEC, 'before' => true, 'after' => true],
        'و' => ['isolated' => 0xFEED, 'final' => 0xFEEE, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ى' => ['isolated' => 0xFEEF, 'final' => 0xFEF0, 'initial' => null, 'medial' => null, 'before' => true, 'after' => false],
        'ي' => ['isolated' => 0xFEF1, 'final' => 0xFEF2, 'initial' => 0xFEF3, 'medial' => 0xFEF4, 'before' => true, 'after' => true],
    ];

    /**
     * Converts logical Arabic into shaped visual-order glyphs for Dompdf, which
     * does not perform Arabic shaping or bidirectional layout itself.
     */
    public function forDompdf(?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        return implode("\n", array_map($this->visualLine(...), preg_split('/\R/u', $value) ?: []));
    }

    private function visualLine(string $line): string
    {
        preg_match_all('/[\p{Arabic}\p{M}]+|[A-Za-z0-9@+._\/:\-]+|\s+|./u', $line, $matches);

        $tokens = array_reverse($matches[0]);

        return implode('', array_map(function (string $token): string {
            if (preg_match('/\p{Arabic}/u', $token) === 1) {
                return $this->shapeArabicRun($token);
            }

            return match ($token) {
                '(' => ')',
                ')' => '(',
                '[' => ']',
                ']' => '[',
                '{' => '}',
                '}' => '{',
                default => $token,
            };
        }, $tokens));
    }

    private function shapeArabicRun(string $run): string
    {
        $characters = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clusters = [];

        foreach ($characters as $character) {
            if (preg_match('/\p{M}/u', $character) === 1 && $clusters !== []) {
                $clusters[array_key_last($clusters)] .= $character;

                continue;
            }

            $clusters[] = $character;
        }

        $shaped = [];

        foreach ($clusters as $index => $cluster) {
            $base = mb_substr($cluster, 0, 1);
            $marks = mb_substr($cluster, 1);
            $form = self::FORMS[$base] ?? null;

            if ($form === null) {
                $shaped[] = $cluster;

                continue;
            }

            $previous = self::FORMS[mb_substr($clusters[$index - 1] ?? '', 0, 1)] ?? null;
            $next = self::FORMS[mb_substr($clusters[$index + 1] ?? '', 0, 1)] ?? null;
            $connectsBefore = $previous !== null && $previous['after'] && $form['before'];
            $connectsAfter = $next !== null && $form['after'] && $next['before'];

            $codepoint = match (true) {
                $connectsBefore && $connectsAfter && $form['medial'] !== null => $form['medial'],
                $connectsBefore && $form['final'] !== null => $form['final'],
                $connectsAfter && $form['initial'] !== null => $form['initial'],
                default => $form['isolated'],
            };

            $shaped[] = mb_chr($codepoint, 'UTF-8').$marks;
        }

        return implode('', array_reverse($shaped));
    }
}
