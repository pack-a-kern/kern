<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Forms;

use App\Domain\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCheck;
use App\Domain\TaxKnowledge\Models\FormStructure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Every structure file ANAF lists for a declaration, read.
 *
 * The page listing them (`fiscal_parameters.forms.D212.index_url`) links one PDF per year — the
 * formula of every field. Each is downloaded through the allowlisted fetcher, its text taken by the
 * document extraction service, and only its address and hash kept: the portal is the copy, and the
 * hash says whether ANAF has since changed it.
 */
final readonly class FormStructureSource
{
    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private TextExtractionGateway $extraction,
        private ConfigRepository $config,
    ) {
    }

    /** @return list<FormText> oldest year first */
    public function read(string $formCode): array
    {
        $form = $this->config->array("fiscal_parameters.forms.{$formCode}");
        $source = self::string($form, 'source');
        $index = $this->fetcher->fetch($source, self::string($form, 'index_url'));

        preg_match_all(self::string($form, 'link'), $index->body, $links);

        $read = [];

        foreach (array_unique($links[1]) as $link) {
            $url = $this->absolute($link, $index->finalUrl);
            $file = $this->fetcher->fetch($source, $url);
            $text = $this->text($file->body);
            $year = $this->year($form, $url, $text ?? '');

            if ($year === null) {
                throw new RuntimeException("The structure file at {$url} names no year it is for.");
            }

            $structure = FormStructure::query()->updateOrCreate(['url' => $url], [
                'form_code' => $formCode,
                'form_year' => $year,
                'content_hash' => hash('sha256', $file->body),
                'unmatched' => $text === null ? [['kind' => ParameterCheck::UNREADABLE->value]] : null,
                'read_at' => now('UTC'),
            ]);

            $read[] = new FormText($structure, $text);
        }

        usort($read, static fn (FormText $a, FormText $b): int => $a->structure->form_year <=> $b->structure->form_year);

        return $read;
    }

    /** The text of a PDF, held in memory only — or null when the service refuses that file. */
    private function text(string $pdf): ?string
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Could not open a memory stream for a structure file.');
        }

        try {
            fwrite($stream, $pdf);
            rewind($stream);

            return $this->extraction->extractNativeText($stream)->joinedText()->reveal();
        } catch (DocumentRejectedByExtractionException) {
            return null;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<mixed> $form */
    private function year(array $form, string $url, string $text): ?int
    {
        foreach ([[self::string($form, 'year_in_name'), rawurldecode($url)], [self::string($form, 'year_in_text'), $text]] as [$pattern, $subject]) {
            if (preg_match($pattern, $subject, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /** A link as the page writes it, made whole against the page's own address. */
    private function absolute(string $link, string $page): string
    {
        if (preg_match('~^https?://~i', $link) === 1) {
            return $link;
        }

        return rtrim(dirname($page), '/') . '/' . ltrim($link, '/');
    }

    /** @param array<mixed> $form */
    private static function string(array $form, string $key): string
    {
        $value = $form[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException("fiscal_parameters.forms: '{$key}' is missing.");
    }
}
