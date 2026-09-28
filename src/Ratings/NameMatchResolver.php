<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

/**
 * Последний шаг сопоставления, когда ИНН/связка/ISIN не дали результата —
 * общий для всех 7 импортёров current_ratings/rating_actions (решение
 * пользователя, сентябрь 2026, см. докблок NameMatchReviews).
 *
 * Возвращает issuer_id ТОЛЬКО для названия, которое администратор уже
 * подтвердил (и только если у источника нет ИНН — для источника с ИНН
 * подтверждение живёт в issuer_spv_links и срабатывает раньше, на уровне
 * ИНН). Всё остальное — кандидат по точному имени или "по корню"
 * превращается в предложение на подтверждение и в базу не пишется.
 */
final class NameMatchResolver
{
    public function __construct(
        private readonly IssuerMatcher $matcher,
        private readonly NameMatchReviews $reviews,
    ) {
    }

    /**
     * @param array<int, string> $candidateNames названия компании в источнике (одно — у выгрузок, все названия в кавычках — у новостей)
     * @param array<int, true> $skipIssuerIds issuer_id, уже сопоставленные напрямую (в этом действии или прогоне) — их не предлагаем
     * @return array{issuerId: ?int, proposed: int}
     */
    public function resolve(
        string $agency,
        ?string $sourceInn,
        array $candidateNames,
        ?string $sourceTitle,
        ?string $sourceUrl,
        array $skipIssuerIds = [],
    ): array {
        $approvedId = $this->findApproved($sourceInn, $candidateNames);
        if ($approvedId !== null) {
            return ['issuerId' => $approvedId, 'proposed' => 0];
        }

        $proposed = 0;
        foreach ($candidateNames as $name) {
            $matchType = 'exact_name';
            $issuerId = $this->matcher->findIssuerIdByName($name);
            if ($issuerId === null) {
                $matchType = 'root_name';
                $issuerId = $this->matcher->findIssuerIdByRootName($name);
            }
            if ($issuerId === null || isset($skipIssuerIds[$issuerId])) {
                continue;
            }

            $status = $this->reviews->propose($agency, $matchType, $sourceInn, $name, $issuerId, $sourceTitle, $sourceUrl);
            if ($status === NameMatchReviews::PROPOSED) {
                $proposed++;
            }
        }

        return ['issuerId' => null, 'proposed' => $proposed];
    }

    /**
     * Только уже подтверждённое название, без новых предложений — для
     * случаев, когда источник не смог отдать ИНН по техническим причинам
     * (например, карточка Эксперт РА не открылась): предлагать пару по
     * названию тут нельзя, у компании может быть ИНН, которого мы не увидели.
     *
     * @param array<int, string> $candidateNames
     */
    public function findApproved(?string $sourceInn, array $candidateNames): ?int
    {
        if (IssuerMatcher::normalizeInn($sourceInn) !== null) {
            return null;
        }
        foreach ($candidateNames as $name) {
            $issuerId = $this->matcher->findIssuerIdByApprovedName($name);
            if ($issuerId !== null) {
                return $issuerId;
            }
        }

        return null;
    }
}
