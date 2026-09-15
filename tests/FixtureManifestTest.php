<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The manifest and the directory have to agree.
 *
 * Every other test in this namespace reads the manifest, so a fixture file added without an entry would sit on disk
 * being tested by nothing. This is what turns that into a failure, and it is also what keeps the provenance honest:
 * a fixture with no source URL is a transaction nobody can check came from where it says.
 */
class FixtureManifestTest extends TestCase
{
    public function test_every_file_on_disk_has_a_manifest_entry(): void
    {
        $listed = [];
        foreach (['chain', 'negative', 'perturbed'] as $section) {
            foreach (TransactionFixtures::section($section) as $fixture) {
                $listed[] = $fixture['file'];
            }
        }

        $directory = TransactionFixtures::directory();
        $found = [];
        foreach (['chain', 'negative', 'perturbed'] as $section) {
            foreach (glob($directory.'/'.$section.'/*.hex') as $path) {
                $found[] = $section.'/'.basename($path);
            }
        }

        sort($listed);
        sort($found);

        $this->assertSame($found, $listed);
    }

    public function test_every_chain_fixture_records_where_it_came_from(): void
    {
        foreach (TransactionFixtures::section('chain') as $fixture) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fixture['tx_hash'], $fixture['id']);
            $this->assertStringStartsWith('https://', $fixture['source_url'], $fixture['id']);
            $this->assertSame([$fixture['tx_hash']], $fixture['source_request']['_tx_hashes'], $fixture['id']);
            $this->assertNotSame('', trim($fixture['found_by']), $fixture['id'].' does not say how it was found.');
            $this->assertNotSame('', trim($fixture['notes']), $fixture['id'].' has no note.');
        }
    }

    public function test_every_negative_fixture_says_what_was_changed_and_why_it_matters(): void
    {
        $chainIds = array_column(TransactionFixtures::section('chain'), 'id');

        foreach (TransactionFixtures::section('negative') as $fixture) {
            $this->assertContains($fixture['derived_from'], $chainIds, $fixture['id']);
            $this->assertNotSame('', trim($fixture['mutation']), $fixture['id']);
            $this->assertNotSame('', trim($fixture['must_be_refused_because']), $fixture['id']);
        }
    }

    public function test_the_corpus_holds_at_least_two_negative_fixtures(): void
    {
        $this->assertGreaterThanOrEqual(2, count(TransactionFixtures::section('negative')));
    }

    public function test_fixture_identifiers_are_unique(): void
    {
        foreach (['chain', 'negative', 'perturbed', 'shapes'] as $section) {
            $ids = array_column(TransactionFixtures::section($section), 'id');

            $this->assertSame(array_unique($ids), $ids, 'Duplicate id in '.$section.'.');
        }
    }

    public function test_the_manifest_names_the_network_and_the_provider(): void
    {
        $manifest = TransactionFixtures::manifest();

        $this->assertSame('mainnet', $manifest['network']);
        $this->assertSame('Koios', $manifest['provider']['name']);
        $this->assertStringStartsWith('https://', $manifest['provider']['base_url']);
    }
}
