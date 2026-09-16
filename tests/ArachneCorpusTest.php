<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Script\NativeScript;
use PHPUnit\Framework\TestCase;

/**
 * Whether the vendored corpus is still the corpus it says it is.
 *
 * A conformance suite is worth exactly what its vectors are worth, and a vector edited to make a test pass is worse
 * than no vector at all: it reads as evidence and is a restatement of whatever the code did that day. The corpus
 * carries its own integrity check for this, and these assertions run it here rather than taking it on trust.
 *
 * tests/fixtures/arachne/README.md records the repository and commit the files were copied from.
 */
class ArachneCorpusTest extends TestCase
{
    /**
     * The domain the corpus derives its stand-in key hashes under, quoted from its specification.
     *
     * A vector's script hash has to reproduce in every language, so the key hashes inside it cannot come from a
     * wallet. They are the blake2b-224 of the UTF-8 bytes of this string followed by a label. No private key exists
     * for any of them and nothing can sign for them, which is why the corpus is safe to commit and useless to steal.
     */
    private const COSIGNER_DOMAIN = 'arachne/cosigner/';

    public function test_the_corpus_is_at_the_format_version_this_suite_reads(): void
    {
        $this->assertSame(ArachneCorpus::FORMAT_VERSION, ArachneCorpus::index()['formatVersion']);

        foreach (ArachneCorpus::ids() as $id) {
            $this->assertSame(
                ArachneCorpus::FORMAT_VERSION,
                ArachneCorpus::vector($id)['formatVersion'],
                $id.' is at a format version this suite does not read.'
            );
        }
    }

    /**
     * The index and the directory, in both directions. A vector on disk that the index does not list would be tested
     * by nothing, and an index entry with no file would be a silent hole in the count.
     */
    public function test_the_index_and_the_directory_hold_the_same_vectors(): void
    {
        $listed = [];
        foreach (ArachneCorpus::index()['vectors'] as $entry) {
            $this->assertSame($entry['id'].'.json', $entry['path'], $entry['id'].' is not filed under its own id.');
            $listed[] = $entry['path'];
        }

        $found = [];
        foreach (glob(ArachneCorpus::directory().'/vectors/*/*.json') as $path) {
            $found[] = basename(dirname($path)).'/'.basename($path);
        }

        sort($listed);
        sort($found);

        $this->assertSame($found, $listed);
        $this->assertCount(ArachneCorpus::index()['vectorCount'], $found);
    }

    /**
     * The corpus digest, recomputed from the files.
     *
     * It is a blake2b-224 over one line per vector holding the id and both script hashes, sorted and joined with
     * newlines. Recomputing it here is what turns "these files came from that commit" into something a reader can
     * check rather than something this repository asserts about itself.
     */
    public function test_the_digest_in_the_index_is_the_digest_of_the_files_on_disk(): void
    {
        $lines = [];

        foreach (ArachneCorpus::ids() as $id) {
            $encoding = ArachneCorpus::vector($id)['encoding'];
            $lines[] = $id."\t".$encoding['definite']['scriptHash']."\t".$encoding['cardanoBinary']['scriptHash'];
        }

        sort($lines, SORT_STRING);

        $this->assertSame(
            ArachneCorpus::index()['digest'],
            bin2hex(Blake2b::hash224(implode("\n", $lines))),
            'The vectors on disk are not the vectors the index was built from.'
        );
    }

    /**
     * The index carries each vector's two script hashes as well, so a vector that moved shows up twice.
     */
    public function test_the_index_records_the_hashes_the_vectors_hold(): void
    {
        foreach (ArachneCorpus::index()['vectors'] as $entry) {
            $encoding = ArachneCorpus::vector($entry['id'])['encoding'];

            $this->assertSame($encoding['definite']['scriptHash'], $entry['scriptHash'], $entry['id']);
            $this->assertSame(
                $encoding['cardanoBinary']['scriptHash'],
                $entry['cardanoBinaryScriptHash'],
                $entry['id']
            );
        }
    }

    public function test_the_index_counts_what_the_files_contain(): void
    {
        $cases = 0;
        $sensitive = 0;
        $families = [];

        foreach (ArachneCorpus::ids() as $id) {
            $vector = ArachneCorpus::vector($id);
            $cases += count($vector['satisfaction']);
            $sensitive += $vector['encoding']['encodingSensitive'] === true ? 1 : 0;
            $families[$vector['family']] = ($families[$vector['family']] ?? 0) + 1;
        }

        $index = ArachneCorpus::index();

        $this->assertSame($index['satisfactionCaseCount'], $cases);
        $this->assertSame($index['encodingSensitiveCount'], $sensitive);
        $this->assertSame(count($index['families']), count($families));

        foreach ($index['families'] as $family) {
            $this->assertSame($family['count'], $families[$family['name']], $family['name']);
            $this->assertNotSame('', trim($family['question']), $family['name'].' has no question.');
        }
    }

    /**
     * Every key hash in the corpus, regenerated from its label.
     *
     * This is the assertion that says the corpus contains no key material. Each hash is derived from a short label
     * under a published domain, so anyone can rebuild all 980 of them with no wallet and no seed, and a hash that
     * could not be rebuilt would be one that came from somewhere else.
     */
    public function test_every_key_hash_in_the_corpus_is_derived_from_a_label_and_not_from_a_wallet(): void
    {
        $derived = [];

        foreach (self::cosignerLabels() as $label) {
            $derived[bin2hex(Blake2b::hash224(self::COSIGNER_DOMAIN.$label))] = $label;
        }

        $keyHashes = [];
        foreach (ArachneCorpus::ids() as $id) {
            foreach (ArachneCorpus::vector($id)['shape']['keyHashes'] as $keyHash) {
                $keyHashes[$keyHash] = $id;
            }
        }

        $this->assertSame(980, count($keyHashes), 'The corpus no longer holds the key hashes this test read.');

        $unexplained = [];
        foreach ($keyHashes as $keyHash => $id) {
            if (! isset($derived[$keyHash])) {
                $unexplained[] = $keyHash.' in '.$id;
            }
        }

        $this->assertSame([], $unexplained, implode("\n", array_merge(
            ['A key hash in the corpus is not the blake2b-224 of any cosigner label:'],
            $unexplained,
        )));
    }

    /**
     * The chain observations the corpus carries, and this package's answer for each one.
     *
     * Encoding and satisfaction are settled offline. Whether a node accepts a transaction carrying a given script is
     * a separate question that only submission answers, and the corpus answers it for three submissions on preprod.
     * They are the useful ones: an `all` with no sub-scripts was spent with no vkey witness at all, which is the
     * ledger agreeing that anyone holding the script can spend what it guards, and an `any` with no sub-scripts
     * refused every attempt, which is the ledger agreeing that its funds are locked for good.
     *
     * An observation is evidence and cannot be recomputed, so what this suite can do with one is check that its own
     * answer for the same case is the answer the node gave. A disagreement would be a defect here rather than a
     * failing vector, which is why it is asserted rather than counted.
     */
    public function test_this_package_agrees_with_every_chain_observation_the_corpus_carries(): void
    {
        $observations = [];

        foreach (ArachneCorpus::ids() as $id) {
            foreach (ArachneCorpus::vector($id)['onchain'] as $observation) {
                $observations[] = [$id, $observation];
            }
        }

        $this->assertCount(
            ArachneCorpus::index()['observationCount'],
            $observations,
            'The index and the vectors disagree about how many submissions the corpus records.'
        );
        $this->assertCount(3, $observations, 'The number of chain observations in the corpus has moved.');

        $evaluated = 0;

        foreach ($observations as [$id, $observation]) {
            $vector = ArachneCorpus::vector($id);
            $script = NativeScript::fromArray($vector['script']);

            $this->assertContains($observation['network'], ['preview', 'preprod'], $id);

            if ($observation['accepted'] === true) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $observation['txHash'], $id.' tx hash');
                $this->assertArrayNotHasKey('error', $observation, $id.' was accepted and carries an error.');
            } else {
                $this->assertNotSame('', trim($observation['error']), $id.' was refused and says nothing.');
                $this->assertArrayNotHasKey('txHash', $observation, $id.' was refused and carries a tx hash.');
                $this->assertStringContainsString(
                    $script->hashHex(),
                    $observation['error'],
                    $id.' was refused over a script hash this package does not compute.'
                );
            }

            // Storing a script as a reference output executes nothing, so there is no case to evaluate against it.
            if (! isset($observation['caseId'])) {
                $this->assertSame('store-reference-script', $observation['action'], $id);

                continue;
            }

            $case = self::satisfactionCase($vector, $observation['caseId']);

            $this->assertSame(
                $observation['accepted'],
                $script->isSatisfiedBy($case['signers'], $case['validityStart'] ?? null, $case['validityEnd'] ?? null),
                $id.' case '.$observation['caseId'].': the node and this package disagree.'
            );
            $this->assertSame(
                $observation['accepted'],
                $case['expected'],
                $id.' case '.$observation['caseId'].': the node and the corpus disagree.'
            );

            $evaluated++;
        }

        $this->assertSame(2, $evaluated, 'The number of observations this suite can evaluate has moved.');
    }

    /**
     * The satisfaction case an observation names.
     *
     * @param  array<string, mixed>  $vector
     * @return array<string, mixed>
     */
    private static function satisfactionCase(array $vector, string $caseId): array
    {
        foreach ($vector['satisfaction'] as $case) {
            if ($case['id'] === $caseId) {
                return $case;
            }
        }

        self::fail($vector['id'].' records an observation for case '.$caseId.', which it does not carry.');
    }

    /**
     * A vector says what it is for, and the oddities it carries are recorded rather than left to be discovered.
     */
    public function test_every_vector_says_which_question_it_answers(): void
    {
        foreach (ArachneCorpus::ids() as $id) {
            $vector = ArachneCorpus::vector($id);

            $this->assertNotSame('', trim($vector['question']), $id.' answers no stated question.');
            $this->assertSame($id, $vector['id']);
            $this->assertStringStartsWith($vector['family'].'/', $id);

            foreach ($vector['remarks'] as $remark) {
                $this->assertNotSame('', trim($remark['code']), $id.' carries a remark with no code.');
                $this->assertNotSame('', trim($remark['detail']), $id.' carries a remark with no detail.');
            }
        }
    }

    /**
     * The provenance file, and the two values in it that have to match the corpus it describes.
     */
    public function test_the_corpus_records_where_it_came_from(): void
    {
        $readme = (string) file_get_contents(ArachneCorpus::directory().'/README.md');

        $this->assertStringContainsString('https://github.com/Crypto2099/arachne', $readme);
        $this->assertStringContainsString(ArachneCorpus::index()['digest'], $readme, 'The README names another digest.');
        $this->assertStringContainsString(
            ArachneCorpus::index()['generator'],
            $readme,
            'The README names another generator version.'
        );
        $this->assertMatchesRegularExpression(
            '/\b[0-9a-f]{40}\b/',
            $readme,
            'The README does not name the commit the files were copied from.'
        );
    }

    /**
     * The label space the corpus draws its cosigners from.
     *
     * `c` is the ordinary cosigner, `lead` and `deleg` are the two roles in the nested families, and the rest are the
     * per-cohort prefixes the federation and encoding-boundary families use so that two members of one federation
     * never share a key.
     *
     * @return list<string>
     */
    private static function cosignerLabels(): array
    {
        $labels = [];

        foreach (['c', 'lead', 'deleg'] as $prefix) {
            for ($i = 0; $i < 420; $i++) {
                $labels[] = $prefix.$i;
            }
        }

        $labels[] = 'ebx0';

        for ($m = 0; $m < 26; $m++) {
            for ($i = 0; $i < 26; $i++) {
                $labels[] = 'm'.$m.'k'.$i;
            }
        }

        for ($b = 0; $b < 8; $b++) {
            for ($m = 0; $m < 26; $m++) {
                for ($i = 0; $i < 26; $i++) {
                    $labels[] = 'b'.$b.'m'.$m.'k'.$i;
                }
            }
        }

        for ($w = 0; $w < 30; $w++) {
            for ($i = 0; $i < 30; $i++) {
                $labels[] = 'eb'.$w.'k'.$i;
            }
        }

        return $labels;
    }
}
