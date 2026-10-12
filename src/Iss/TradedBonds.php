<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

/**
 * Список облигаций, которые сегодня торгуются на бирже, — один запрос на
 * весь рынок. Им пользуются сверка статусов (SecurityStatusSync) и
 * импортёр оферт (OffersImporter): «бумага есть на бирже» должно значить
 * одно и то же в обоих местах.
 */
final class TradedBonds
{
    /**
     * ISIN → доски, на которых бумага сейчас торгуется. Строка со STATUS,
     * отличным от «A», торговлей не считается (АльфаБО-21 два дня после
     * досрочного погашения висела в списке со статусом «N»).
     *
     * @return array<string, array<int, string>>
     */
    public static function fetch(IssClient $iss): array
    {
        $response = $iss->getJson('/engines/stock/markets/bonds/securities.json', [
            'iss.only' => 'securities',
            'securities.columns' => 'SECID,BOARDID,ISIN,STATUS',
        ]);
        $market = [];
        foreach (IssClient::block($response, 'securities') as $row) {
            $isin = trim((string) ($row['ISIN'] ?? ''));
            if ($isin !== '' && ($row['STATUS'] ?? null) === 'A') {
                $market[$isin][] = (string) ($row['BOARDID'] ?? '');
            }
        }

        return $market;
    }
}
