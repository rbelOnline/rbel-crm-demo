<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills {{placeholder}} tokens in a Word .docx (body, headers and footers).
 *
 * Word often splits what you typed as "{{full_name}}" across several runs
 * (<w:r><w:t>{{</w:t></w:r><w:r><w:t>full_name}}</w:t></w:r>), e.g. after a
 * spell-check or formatting change. Each paragraph's text is therefore read as
 * a whole, tokens are located in it, and the replacement is written into the run
 * where the token starts (keeping that run's formatting); the rest of the token is
 * removed from the following runs. Values are set as text, so they are XML-escaped.
 */
class DocxFiller
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const TOKEN = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    /** Largest unzipped body/header/footer part accepted (uploads themselves are at most 10 MB). */
    private const MAX_PART_BYTES = 64 * 1024 * 1024;

    /** Placeholder names used in a .docx (lower-case, unique). */
    public function placeholders(string $path): array
    {
        $found = [];
        foreach ($this->parts($path) as $xml) {
            $dom = $this->load($xml);
            foreach ($this->xpath($dom)->query('//w:p') as $p) {
                preg_match_all(self::TOKEN, $this->paragraphText($dom, $p), $m);
                array_push($found, ...array_map('strtolower', $m[1]));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Write a filled copy of $source to $target.
     *
     * @param  array<string, string>  $values
     * @return list<string> placeholders that had no value (left as-is in the document)
     */
    public function fill(string $source, string $target, array $values): array
    {
        if (! copy($source, $target)) {
            throw new RuntimeException('Could not copy the template.');
        }

        $zip = new ZipArchive;
        if ($zip->open($target) !== true) {
            throw new RuntimeException('The template is not a valid Word (.docx) file.');
        }

        $unfilled = [];
        foreach ($this->partNames($zip) as $name) {
            $dom = $this->load($zip->getFromName($name));
            $changed = false;

            foreach ($this->xpath($dom)->query('//w:p') as $p) {
                $changed = $this->fillParagraph($dom, $p, $values, $unfilled) || $changed;
            }

            if ($changed) {
                $zip->addFromString($name, $dom->saveXML());
            }
        }
        $zip->close();

        return array_values(array_unique($unfilled));
    }

    private function fillParagraph(DOMDocument $dom, \DOMElement $p, array $values, array &$unfilled): bool
    {
        $nodes = iterator_to_array($this->xpath($dom)->query('.//w:t', $p));
        if (! $nodes) {
            return false;
        }

        $texts = array_map(fn ($n) => $n->textContent, $nodes);
        $full = implode('', $texts);
        if (! preg_match_all(self::TOKEN, $full, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return false;
        }

        // Byte offset where each run's text starts within the paragraph text.
        $starts = [];
        $pos = 0;
        foreach ($texts as $i => $t) {
            $starts[$i] = $pos;
            $pos += strlen($t);
        }
        $runAt = function (int $offset) use ($starts): int {
            for ($i = count($starts) - 1; $i > 0 && $starts[$i] > $offset; $i--);

            return $i;
        };

        $changed = false;
        // Right to left, so earlier offsets stay valid while later text changes.
        foreach (array_reverse($matches) as $m) {
            [$token, $start] = $m[0];
            $key = strtolower($m[1][0]);
            if (! array_key_exists($key, $values)) {
                $unfilled[] = $key;

                continue;
            }

            $end = $start + strlen($token);
            $first = $runAt($start);
            $last = $runAt($end - 1);
            $value = preg_replace('/[\r\n]+/', ' ', (string) $values[$key]);

            if ($first === $last) {
                $texts[$first] = substr_replace($texts[$first], $value, $start - $starts[$first], strlen($token));
            } else {
                $texts[$first] = substr($texts[$first], 0, $start - $starts[$first]).$value;
                for ($i = $first + 1; $i < $last; $i++) {
                    $texts[$i] = '';
                }
                $texts[$last] = substr($texts[$last], $end - $starts[$last]);
            }
            $changed = true;
        }

        if ($changed) {
            foreach ($nodes as $i => $node) {
                if ($node->textContent !== $texts[$i]) {
                    $node->textContent = $texts[$i];
                    // Keep leading/trailing spaces of the filled text.
                    $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                }
            }
        }

        return $changed;
    }

    private function paragraphText(DOMDocument $dom, \DOMElement $p): string
    {
        $text = '';
        foreach ($this->xpath($dom)->query('.//w:t', $p) as $t) {
            $text .= $t->textContent;
        }

        return $text;
    }

    /** @return \Generator<string> the XML of the body, headers and footers */
    private function parts(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a valid Word (.docx) file.');
        }
        try {
            foreach ($this->partNames($zip) as $name) {
                yield $zip->getFromName($name);
            }
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function partNames(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'word/document.xml' || preg_match('#^word/(header|footer)\d*\.xml$#', $name)) {
                // A tiny upload can unzip to gigabytes ("zip bomb"); real parts are far smaller.
                if (($zip->statIndex($i)['size'] ?? 0) > self::MAX_PART_BYTES) {
                    throw new RuntimeException('The Word file is too large to process.');
                }
                $names[] = $name;
            }
        }

        if (! in_array('word/document.xml', $names, true)) {
            throw new RuntimeException('The file is not a valid Word (.docx) file.');
        }

        return $names;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }

    private function load(string $xml): DOMDocument
    {
        // Word never writes a DOCTYPE. Refusing one blocks entity-expansion ("billion
        // laughs") and external-entity attacks, which LIBXML_PARSEHUGE would not limit.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('The Word file could not be read.');
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        // LIBXML_NONET: never fetch external resources while parsing an uploaded file.
        if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('The Word file could not be read.');
        }

        return $dom;
    }
}
