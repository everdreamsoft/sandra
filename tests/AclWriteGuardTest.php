<?php

declare(strict_types=1);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Acl\AclResolver;
use SandraCore\Acl\WriteGuard;
use SandraCore\DatabaseAdapter;
use SandraCore\EntityFactory;
use SandraCore\Exception\AccessDeniedException;

/**
 * Write side of the graph ACL: a triplet has no file of its own, so the right
 * to write it is derived from its endpoints — the mirror of TripletVisibility.
 *
 * The principal here holds a WRITE grant and no read grant at all, which is
 * representable on purpose: AclResolver collects the two grants independently
 * and never intersects them.
 */
class AclWriteGuardTest extends SandraTestCase
{
    private WriteGuard $writer;
    private WriteGuard $admin;
    private int $note;
    private int $otherNote;
    private int $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $notes = new EntityFactory('note', 'notes_file', $this->system);
        $this->note = (int) $notes->createNew(['title' => 'public note'])->subjectConcept->idConcept;
        $this->otherNote = (int) $notes->createNew(['title' => 'another note'])->subjectConcept->idConcept;

        $secrets = new EntityFactory('secret', 'secrets_file', $this->system);
        $this->secret = (int) $secrets->createNew(['title' => 'owner attribution'])->subjectConcept->idConcept;

        $keys = new EntityFactory('api_key', 'keys_file', $this->system);
        $writerId = (int) $keys->createNew(['name' => 'annotating-agent'])->subjectConcept->idConcept;
        $adminId = (int) $keys->createNew(['name' => 'root-agent'])->subjectConcept->idConcept;

        $sc = $this->system->systemConcept;
        // Write on notes_file only — and no read grant anywhere.
        DatabaseAdapter::rawCreateTriplet($writerId, (int) $sc->get(AclResolver::ALLOW_WRITE), (int) $sc->get('notes_file'), $this->system);
        DatabaseAdapter::rawCreateTriplet($adminId, (int) $sc->get(AclResolver::ALLOW_WRITE), (int) $sc->get(AclResolver::WILDCARD_FILE), $this->system);

        $this->writer = WriteGuard::forAccess($this->system, AclResolver::resolve($this->system, $writerId));
        $this->admin = WriteGuard::forAccess($this->system, AclResolver::resolve($this->system, $adminId));
    }

    public function testWriteGrantDoesNotRequireAReadGrant(): void
    {
        $sc = $this->system->systemConcept;

        $this->writer->assertCanLink($this->note, (int) $sc->get('relatesTo'), $this->otherNote);
        $this->assertTrue(true, 'both endpoints sit in the one file this principal may write');
    }

    public function testLinkIntoAnUnwritableFileIsRefused(): void
    {
        $verb = (int) $this->system->systemConcept->get('owner');

        $this->expectException(AccessDeniedException::class);
        $this->writer->assertCanLink($this->note, $verb, $this->secret);
    }

    public function testLinkOutOfAnUnwritableFileIsRefused(): void
    {
        $verb = (int) $this->system->systemConcept->get('about');

        $this->assertFalse($this->writer->mayLink($this->secret, $verb, $this->note));
    }

    public function testTaggingWithBareVocabularyIsAnOrdinaryWrite(): void
    {
        $sc = $this->system->systemConcept;

        $this->writer->assertCanLink($this->note, (int) $sc->get('hasTag'), (int) $sc->get('draft'));
        $this->assertTrue(true, 'one endpoint carries a writable file, the other is vocabulary');
    }

    public function testLinkingTwoBareConceptsTakesTheWildcard(): void
    {
        $sc = $this->system->systemConcept;
        $subject = (int) $sc->get('draft');
        $verb = (int) $sc->get('impliesTag');
        $target = (int) $sc->get('unreviewed');

        $this->assertFalse($this->writer->mayLink($subject, $verb, $target), 'the shared dictionary is not writer-owned');
        $this->admin->assertCanLink($subject, $verb, $target);
        $this->assertTrue(true);
    }

    public function testAclVerbsCannotBeWrittenWithoutTheWildcard(): void
    {
        $sc = $this->system->systemConcept;

        // Endpoints alone would allow this: the note is writable and a file
        // concept is bare vocabulary. Only the protected-verb rule stops it.
        foreach (AclResolver::PROTECTED_VERBS as $verb) {
            $this->assertFalse(
                $this->writer->mayLink($this->note, (int) $sc->get($verb), (int) $sc->get('secrets_file')),
                "$verb must not be writable without the write wildcard"
            );
        }

        $this->admin->assertCanLink($this->note, (int) $sc->get(AclResolver::ALLOW_ACCESS), (int) $sc->get('secrets_file'));
        $this->assertTrue(true);
    }

    public function testRetargetCannotOverwriteAnUnwritableLink(): void
    {
        $verb = (int) $this->system->systemConcept->get('owner');
        // Root wires the note to a secret. The writer never saw this link.
        DatabaseAdapter::rawCreateTriplet($this->note, $verb, $this->secret, $this->system);

        // assertCanLink alone would pass — both the note and the new target are
        // writable. Only the retarget check sees what is being overwritten.
        $this->writer->assertCanLink($this->note, $verb, $this->otherNote);

        $this->expectException(AccessDeniedException::class);
        DatabaseAdapter::rawCreateTriplet($this->note, $verb, $this->otherNote, $this->system, 1, true, $this->writer);
    }

    public function testRetargetIsAllowedWhenTheCurrentTargetIsWritable(): void
    {
        $verb = (int) $this->system->systemConcept->get('supersedes');
        DatabaseAdapter::rawCreateTriplet($this->note, $verb, $this->otherNote, $this->system);

        $third = (int) (new EntityFactory('note', 'notes_file', $this->system))
            ->createNew(['title' => 'third'])->subjectConcept->idConcept;

        $linkId = DatabaseAdapter::rawCreateTriplet($this->note, $verb, $third, $this->system, 1, true, $this->writer);
        $this->assertNotNull($linkId);
    }

    public function testUnscopedWritesAreUntouched(): void
    {
        $verb = (int) $this->system->systemConcept->get('owner');
        $linkId = DatabaseAdapter::rawCreateTriplet($this->note, $verb, $this->secret, $this->system);

        $this->assertNotNull($linkId, 'no principal, no guard, legacy behaviour');
    }
}
