<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $page */
    public function upsert(int $siteId, array $page): int
    {
        $sql = <<<SQL
        INSERT INTO pages (site_id, url, title, content, content_hash, http_status, is_matched, matched_entities, crawled_at)
        VALUES (:site_id, :url, :title, :content, :content_hash, :http_status, 0, NULL, :crawled_at)
        ON CONFLICT(site_id, url) DO UPDATE SET
            title = CASE WHEN pages.is_matched = 1 THEN pages.title ELSE excluded.title END,
            content = CASE WHEN pages.is_matched = 1 THEN pages.content ELSE excluded.content END,
            content_hash = CASE WHEN pages.is_matched = 1 THEN pages.content_hash ELSE excluded.content_hash END,
            http_status = CASE WHEN pages.is_matched = 1 THEN pages.http_status ELSE excluded.http_status END,
            crawled_at = CASE WHEN pages.is_matched = 1 THEN pages.crawled_at ELSE excluded.crawled_at END
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $content = (string) ($page['text'] ?? '');
        $url = (string) ($page['url'] ?? '');
        $title = (string) ($page['title'] ?? '');
        $httpStatus = isset($page['status']) ? (int) $page['status'] : null;
        $stmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
            ':title' => $title,
            ':content' => $content,
            ':content_hash' => hash('sha256', $content),
            ':http_status' => $httpStatus,
            ':crawled_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $idStmt = $this->pdo->prepare('SELECT id FROM pages WHERE site_id = :site_id AND url = :url');
        $idStmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
        ]);

        return (int) $idStmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findBySiteAndUrl(int $siteId, string $url): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, site_id, url, is_matched, matched_entities, crawled_at FROM pages WHERE site_id = :site_id AND url = :url'
        );
        $stmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int, string> $entityNames */
    public function markMatched(int $pageId, array $entityNames): void
    {
        $entityNames = array_values(array_unique(array_map(static fn (string $name): string => trim($name), $entityNames)));
        $entityNames = array_values(array_filter($entityNames, static fn (string $name): bool => $name !== ''));

        $stmt = $this->pdo->prepare(
            "UPDATE pages
             SET is_matched = 1,
                 matched_entities = :matched_entities
             WHERE id = :id"
        );
        $stmt->execute([
            ':matched_entities' => $entityNames === [] ? null : json_encode($entityNames, JSON_THROW_ON_ERROR),
            ':id' => $pageId,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentBySite(int $siteId, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                id,
                site_id,
                url,
                title,
                http_status,
                is_matched,
                matched_entities,
                crawled_at
            FROM pages
            WHERE site_id = :site_id
            ORDER BY crawled_at DESC, id DESC
            LIMIT :limit
            OFFSET :offset
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countBySite(int $siteId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM pages WHERE site_id = :site_id');
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function summaryBySite(int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
            SELECT
                COUNT(*) AS pages_indexed,
                SUM(CASE WHEN is_matched = 1 THEN 1 ELSE 0 END) AS pages_matched,
                SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END) AS pages_with_http_errors,
                MAX(crawled_at) AS last_page_crawled_at
            FROM pages
            WHERE site_id = :site_id
            SQL
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return [
                'pages_indexed' => 0,
                'pages_matched' => 0,
                'pages_with_http_errors' => 0,
                'last_page_crawled_at' => null,
            ];
        }

        return $row;
    }
}
