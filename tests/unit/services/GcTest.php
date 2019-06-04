<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craftunit\services;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\records\User;
use craft\services\Gc;
use craftunit\fixtures\EntryFixture;
use craftunit\fixtures\EntryTypeFixture;
use craftunit\fixtures\SectionsFixture;
use craftunit\fixtures\SessionsFixture;
use craftunit\fixtures\UsersFixture;
use craftunit\fixtures\VolumesFixture;
use DateInterval;
use DateTime;
use Exception;
use UnitTester;
use yii\base\InvalidArgumentException;

/**
 * Unit tests for the garbage collector service.
 *
 * @todo Test search index removal
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since 3.2
 */
class GcTest extends Unit
{
    // Public Properties
    // =========================================================================

    /**
     * @var UnitTester
     */
    protected $tester;

    /**
     * @var Gc
     */
    protected $gc;

    // Public Methods
    // =========================================================================

    /**
     * @return array
     */
    public function _fixtures(): array
    {
        return [
            'sessions' => [
                'class' => SessionsFixture::class
            ],
            'entry-types' => [
                'class' => EntryTypeFixture::class
            ],
            'entries' => [
                'class' => EntryFixture::class
            ],
            'users' => [
                'class' => UsersFixture::class
            ],
            'sections' => [
                'class' => SectionsFixture::class
            ],
            'volumes' => [
                'class' => VolumesFixture::class
            ]
        ];
    }

    // Tests
    // =========================================================================

    /**
     *
     */
    public function testRunForDeletedEntriesWithDefaultDuration()
    {
        $this->_doEntryTest(1, [
            'Deleted 40 days ago',
        ]);
    }

    /**
     *
     */
    public function testRunForDeletedEntriesWithCustomDuration()
    {
        // 5 Days
        Craft::$app->getConfig()->getGeneral()->softDeleteDuration = 432000;

        $this->_doEntryTest(2, [
            'Deleted 40 days ago',
            'Deleted 25 days ago',
        ]);
    }

    /**
     *
     */
    public function testRunDeleteAllTrashed()
    {
        $this->gc->deleteAllTrashed = true;

        $this->_doEntryTest(3, [
            'Deleted 40 days ago',
            'Deleted 25 days ago',
            'Deleted today'
        ]);
    }

    /**
     * @dataProvider gcDataProvider
     *
     * @param int $remainingCount
     * @param string $leftoverId
     * @param string $table
     * @param array $ids
     */
    public function testGc(int $remainingCount, string $leftoverId, string $table, array $ids)
    {
        $this->gc->run(true);

        $items = (new Query())
            ->from([$table])
            ->where(['id' => $ids])
            ->all();

        $this->assertCount($remainingCount, $items);
        $this->assertSame((string)ArrayHelper::firstValue($items)['id'], $leftoverId);
    }

    /**
     *
     */
    public function testRunForExpiringUsers()
    {
        // 2 days
        Craft::$app->getConfig()->getGeneral()->purgePendingUsersDuration = 172800;

        // Create then with 3 days
        $this->_createExpiringPendingUsers();

        $this->gc->run(true);

        $users = (new Query())
            ->from(Table::USERS)
            ->where(['username' => ['user1', 'user2', 'user3', 'user4']])
            ->all();

        // Nothing actually gets deleted. The elements dateDeleted should be set for user1 and user2 however
        $this->assertCount(4, $users);

        $deletedUsers = (new Query())
            ->from(['users' => Table::USERS])
            ->where(['username' => ['user1', 'user2', 'user3', 'user4']])
            ->leftJoin(Table::ELEMENTS . ' elements', '[[elements.id]] = [[users.id]]')
            ->andWhere('[[elements.dateDeleted]] is not null')
            ->all();

        $this->assertCount(2, $deletedUsers);

        $user3 = ArrayHelper::filterByValue($deletedUsers, 'username', 'user3');
        $user4 = ArrayHelper::filterByValue($deletedUsers, 'username', 'user4');
        $this->assertEmpty($user3);
        $this->assertEmpty($user4);
    }

    // Data Providers
    // =========================================================================

    /**
     * @return array
     * @todo Other GC tables
     *
     */
    public function gcDataProvider(): array
    {
        return [
            [1, '1005', Table::SESSIONS, ['1003', '1004', '1005']],
            [1, '1000', Table::SECTIONS, ['1000', '1001', '1002']],
            [1, '1000', Table::ENTRYTYPES, ['1000', '1001', '1002']],
            [1, '1000', Table::VOLUMES, ['1000', '1001', '1002']],
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function _before()
    {
        parent::_before();

        $this->gc = Craft::$app->getGc();
    }

    // Private Methods
    // =========================================================================

    /**
     * Helper method to check entries are removed. You can pass $expectedRemove
     * to indicate how many entries should be deleted and a $notAllowedTitles to
     * indicate what titles are not allowed to be present.
     *
     * @param int $expectedRemoval
     * @param array|null $notAllowedTitles
     */
    private function _doEntryTest(int $expectedRemoval, array $notAllowedTitles = [])
    {
        // Query with joins is needed to take into account any revisions.
        $totalEntries = (new Query())
            ->select(['entries.id'])
            ->from(['entries' => Table::ENTRIES])
            ->leftJoin(Table::ELEMENTS . ' elements', '[[elements.id]] = [[entries.id]]')
            ->where('[[elements.revisionId]] IS NULL')
            ->distinct()
            ->count();

        $this->gc->run(true);
        $entries = Entry::find()
            ->asArray()
            ->trashed(null)
            ->all();

        $this->assertCount($totalEntries - $expectedRemoval, $entries);

        // Check any non allowed titles. Fail if an entry exists with a title that isn't allowed.
        foreach ($notAllowedTitles as $notAllowedTitle) {
            $doesEntryExistWithThisTitle = ArrayHelper::filterByValue($entries, 'title', $notAllowedTitle);
            if ($doesEntryExistWithThisTitle) {
                $this->fail("Entries were deleted but an entry with title ($notAllowedTitle) still exists");
            }
        }
    }

    /**
     * @throws Exception
     */
    private function _createExpiringPendingUsers()
    {
        $date = (new DateTime('now'))->sub(new DateInterval('P3D'))->format('Y-m-d H:i:s');

        $userRecords = User::find()
            ->where(['username' => ['user1', 'user2']])
            ->all();

        foreach ($userRecords as $userRecord) {
            $userRecord->verificationCodeIssuedDate = $date;
            $userRecord->pending = true;

            if (!$userRecord->save()) {
                throw new InvalidArgumentException('Unable to update user');
            }
        }
    }
}
