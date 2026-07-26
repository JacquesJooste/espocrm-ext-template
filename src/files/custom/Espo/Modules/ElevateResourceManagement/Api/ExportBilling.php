<?php

namespace Espo\Modules\ElevateResourceManagement\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Config;
use Espo\Modules\ElevateResourceManagement\Pdf\HtmlTemplate;
use Espo\Modules\ElevateResourceManagement\Service\ApplicationService;
use Espo\ORM\EntityManager;
use Espo\Tools\Pdf\Builder;
use Espo\Tools\Pdf\Data;
use Espo\Tools\Pdf\Params;

final class ExportBilling implements Action
{
    public function __construct(
        private ApplicationService $service,
        private EntityManager $entityManager,
        private Builder $builder,
        private Config $config,
    ) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        $format = strtolower((string) $request->getRouteParam('format'));

        if (!is_string($id) || !in_array($format, ['pdf', 'csv'], true)) {
            throw new BadRequest('Supported formats are PDF and CSV.');
        }

        $data = $this->service->billingExportData($id);
        $package = $this->entityManager->getRDBRepository('ElevateRmWorkPackage')->getById($id);
        if (!$package) {
            throw new BadRequest('Package not found.');
        }
        $filename = $this->filename((string) ($data['ticketIdentifier'] ?? $id), $format);

        if ($format === 'csv') {
            $response = ResponseComposer::empty()
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->writeBody($this->csv($data));
        } else {
            $contents = $this->builder
                ->setTemplate(new HtmlTemplate($this->html($data), 'Invoice Summary'))
                ->setEngine((string) ($this->config->get('pdfEngine') ?? 'Dompdf'))
                ->build()
                ->printEntity($package, Params::create()->withAcl(false), Data::create());
            $response = ResponseComposer::empty()
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Content-Length', (string) $contents->getLength())
                ->setBody($contents->getStream());
        }

        if ($this->service->shouldAutoMarkInvoiced() && $package->get('lifecycle') === 'ClosedReadyForBilling') {
            $this->service->billing($id, 'mark-invoiced', []);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function html(array $data): string
    {
        $html = '<h1>' . $this->escape((string) ($data['ticketIdentifier'] ?? 'Invoice Summary')) . '</h1>';
        if ($data['ticketName'] ?? null) {
            $html .= '<p class="meta">' . $this->escape((string) $data['ticketName']) . '</p>';
        }
        foreach ((array) ($data['items'] ?? []) as $item) {
            $names = implode(', ', (array) ($item['attendeeNames'] ?? []));
            $activities = '';
            foreach ((array) ($item['activities'] ?? []) as $activity) {
                $activities .= '<li>' . $this->escape((string) $activity) . '</li>';
            }
            $html .= '<div class="block"><strong>' . $this->escape((string) ($item['date'] ?? '')) . ' — ' .
                $this->escape((string) ($item['blockName'] ?? '')) . '</strong><br>' .
                $this->escape((string) ($item['start'] ?? '')) . ' – ' . $this->escape((string) ($item['end'] ?? '')) .
                ' (' . $this->duration((int) ($item['elapsedSeconds'] ?? 0)) . ')<br>' .
                'Team of ' . count((array) ($item['attendeeNames'] ?? [])) . ': ' . $this->escape($names) .
                ($activities ? '<ul>' . $activities . '</ul>' : '') .
                (($item['workNote'] ?? '') !== '' ? '<p>' . nl2br($this->escape((string) $item['workNote'])) . '</p>' : '') .
                '</div>';
        }
        return $html . '<div class="totals">Elapsed: ' . $this->duration((int) ($data['elapsedSeconds'] ?? 0)) .
            '<br>Labour: ' . $this->duration((int) ($data['labourSeconds'] ?? 0)) . '</div>';
    }

    /** @param array<string, mixed> $data */
    private function csv(array $data): string
    {
        $lines = ['Ticket,Date,Work Block,Start,End,Elapsed Seconds,Labour Seconds,Team,Activities,Work Note'];
        foreach ((array) ($data['items'] ?? []) as $item) {
            $lines[] = $this->csvRow([
                $data['ticketIdentifier'] ?? '',
                $item['date'] ?? '',
                $item['blockName'] ?? '',
                $item['start'] ?? '',
                $item['end'] ?? '',
                $item['elapsedSeconds'] ?? 0,
                $item['labourSeconds'] ?? 0,
                implode('; ', (array) ($item['attendeeNames'] ?? [])),
                implode('; ', (array) ($item['activities'] ?? [])),
                $item['workNote'] ?? '',
            ]);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines);
    }

    /** @param mixed[] $row */
    private function csvRow(array $row): string
    {
        return implode(',', array_map(fn (mixed $value): string => '"' . str_replace('"', '""', (string) $value) . '"', $row));
    }

    private function filename(string $identifier, string $format): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $identifier) . "-invoice-summary.$format";
    }

    private function duration(int $seconds): string
    {
        return intdiv($seconds, 3600) . ' hours ' . intdiv($seconds % 3600, 60) . ' minutes';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
