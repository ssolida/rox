<?php

namespace App\Model;

use App\Doctrine\MemberStatusType;
use App\Entity\Member;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Where members live: countries with their number of members (home page block)
 * and, for one country, its regions and members (country page).
 *
 * Members are counted at their active address, with the statuses the search uses.
 */
class PlacesModel
{
    public const array CONTINENTS = ['AF', 'AM', 'EA', 'OC', 'AN'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Countries that have members, grouped by continent and sorted by number of members.
     *
     * @return array<string, list<array{code: string, name: string, slug: string, count: int}>>
     */
    public function getCountriesByContinent(string $locale): array
    {
        $key = 'places.countries.' . preg_replace('/[^a-z_-]/i', '', $locale);
        $countries = $this->cache->get($key, function (ItemInterface $item) use ($locale): array {
            $item->expiresAfter(3600);

            $connection = $this->connection();
            $counts = $connection->executeQuery('
                SELECT g.country_id AS code, COUNT(DISTINCT m.id) AS cnt
                FROM member m
                JOIN address a ON a.member_id = m.id AND a.active = 1
                JOIN geo__names g ON g.geoname_id = a.location
                WHERE m.Status IN (:statuses)
                GROUP BY g.country_id
            ', ['statuses' => MemberStatusType::ACTIVE_SEARCH_ARRAY], ['statuses' => ArrayParameterType::STRING])
                ->fetchAllKeyValue();

            $rows = $connection->executeQuery("
                SELECT c.country_id AS code, c.continent, g.name AS slug, COALESCE(t.content, g.name) AS name
                FROM geo__countries c
                JOIN geo__names g ON g.geoname_id = c.country
                LEFT JOIN geo__names_translations t ON t.object_id = c.country AND t.locale = :locale AND t.field = 'name'
            ", ['locale' => $locale])->fetchAllAssociative();

            $continents = array_fill_keys(self::CONTINENTS, []);
            foreach ($rows as $row) {
                $count = (int) ($counts[$row['code']] ?? 0);
                if (0 === $count || !isset($continents[$row['continent']])) {
                    continue;
                }
                $continents[$row['continent']][$row['code']] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'count' => $count,
                ];
            }

            foreach ($continents as $continent => $countries) {
                usort($countries, fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
                $continents[$continent] = $countries;
            }

            return array_filter($continents);
        });
        // Don't keep an empty list for an hour (a database being filled, for example).
        if ([] === $countries) {
            $this->cache->delete($key);
        }

        return $countries;
    }

    /**
     * @return array{code: string, name: string, slug: string, continent: string, geonameId: int, latitude: float, longitude: float}|null
     */
    public function getCountry(string $code, string $locale): ?array
    {
        $row = $this->connection()->executeQuery("
            SELECT c.country_id AS code, c.continent, g.geoname_id, g.latitude, g.longitude, g.name AS slug,
                COALESCE(t.content, g.name) AS name
            FROM geo__countries c
            JOIN geo__names g ON g.geoname_id = c.country
            LEFT JOIN geo__names_translations t ON t.object_id = c.country AND t.locale = :locale AND t.field = 'name'
            WHERE c.country_id = :code
        ", ['code' => strtoupper($code), 'locale' => $locale])->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'continent' => $row['continent'],
            'geonameId' => (int) $row['geoname_id'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
        ];
    }

    /**
     * Regions (first level of the country) that have members, sorted by number of members.
     *
     * @return array<string, array{code: string, name: string, count: int, geonameId: ?int, latitude: ?float, longitude: ?float}>
     */
    public function getRegions(string $countryCode, string $locale): array
    {
        $connection = $this->connection();
        $counts = $connection->executeQuery('
            SELECT g.admin_1_id AS code, COUNT(DISTINCT m.id) AS cnt
            FROM member m
            JOIN address a ON a.member_id = m.id AND a.active = 1
            JOIN geo__names g ON g.geoname_id = a.location
            WHERE m.Status IN (:statuses) AND g.country_id = :country AND g.admin_1_id IS NOT NULL
            GROUP BY g.admin_1_id
        ', [
            'statuses' => MemberStatusType::ACTIVE_SEARCH_ARRAY,
            'country' => $countryCode,
        ], ['statuses' => ArrayParameterType::STRING])->fetchAllKeyValue();

        $rows = $connection->executeQuery("
            SELECT r.admin_1_id AS code, r.geoname_id, r.latitude, r.longitude, COALESCE(t.content, r.name) AS name
            FROM geo__names r
            LEFT JOIN geo__names_translations t ON t.object_id = r.geoname_id AND t.locale = :locale AND t.field = 'name'
            WHERE r.country_id = :country AND r.feature_code = 'ADM1'
        ", ['country' => $countryCode, 'locale' => $locale])->fetchAllAssociative();

        $regions = [];
        foreach ($rows as $row) {
            $count = (int) ($counts[$row['code']] ?? 0);
            if (0 === $count || isset($regions[$row['code']])) {
                continue;
            }
            $regions[$row['code']] = [
                'code' => (string) $row['code'],
                'name' => $row['name'],
                'count' => $count,
                'geonameId' => (int) $row['geoname_id'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }
        uasort($regions, fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return $regions;
    }

    public function countMembers(string $countryCode, ?string $regionCode): int
    {
        [$from, $parameters, $types] = $this->membersQuery($countryCode, $regionCode);

        return (int) $this->connection()->executeQuery('SELECT COUNT(DISTINCT m.id) ' . $from, $parameters, $types)->fetchOne();
    }

    /**
     * One page of the members of a country (or of one of its regions): hosts first, then the most recently active.
     *
     * @return array{total: int, members: list<Member>}
     */
    public function getMembers(string $countryCode, ?string $regionCode, int $page, int $perPage): array
    {
        $total = $this->countMembers($countryCode, $regionCode);
        [$from, $parameters, $types] = $this->membersQuery($countryCode, $regionCode);

        $ids = $this->connection()->executeQuery(
            "SELECT m.id {$from}
            GROUP BY m.id
            ORDER BY MAX(m.Accommodation = 'yes') DESC, MAX(m.LastActive) DESC
            LIMIT " . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage,
            $parameters,
            $types
        )->fetchFirstColumn();

        $members = [];
        if ($ids) {
            $byId = [];
            foreach ($this->entityManager->getRepository(Member::class)->findBy(['id' => $ids]) as $member) {
                $byId[$member->getId()] = $member;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $members[] = $byId[$id];
                }
            }
        }

        return ['total' => $total, 'members' => $members];
    }

    /**
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function membersQuery(string $countryCode, ?string $regionCode): array
    {
        $where = 'm.Status IN (:statuses) AND g.country_id = :country';
        $parameters = ['statuses' => MemberStatusType::ACTIVE_SEARCH_ARRAY, 'country' => $countryCode];
        if (null !== $regionCode) {
            $where .= ' AND g.admin_1_id = :region';
            $parameters['region'] = $regionCode;
        }

        return [
            '
            FROM member m
            JOIN address a ON a.member_id = m.id AND a.active = 1
            JOIN geo__names g ON g.geoname_id = a.location
            WHERE ' . $where,
            $parameters,
            ['statuses' => ArrayParameterType::STRING],
        ];
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
