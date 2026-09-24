<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Kvk\Service;

use MagoAssistant\Kvk\Data\Sbi2025;

/**
 * SBI 2025 titles (CBS). The KVK open dataset returns bare codes, and a model left to read them
 * itself guesses: "64210" looks like nothing in particular. The table ships with the module.
 */
class SbiCatalog
{
    /**
     * Resolves a code to its own title, or to the nearest parent that has one: the register still
     * holds a few codes that the 2025 table no longer lists at full depth.
     *
     * @param string $code
     * @return array{sbi_code:string,description:string,description_en:string,sector:string}|null
     */
    public function describe(string $code): ?array
    {
        $titles = Sbi2025::TITLES;
        $code = preg_replace('/\D/', '', $code) ?? '';

        for ($probe = $code; strlen($probe) >= 2; $probe = substr($probe, 0, -1)) {
            if (!isset($titles[$probe])) {
                continue;
            }
            [$nl, $en, $section] = $titles[$probe];

            return [
                'sbi_code' => $code,
                'description' => $nl,
                'description_en' => $en,
                'sector' => isset($titles[$section]) ? $this->sentenceCase($titles[$section][0]) : '',
            ];
        }

        return null;
    }

    private function sentenceCase(string $title): string
    {
        $lower = mb_strtolower($title);

        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }
}
