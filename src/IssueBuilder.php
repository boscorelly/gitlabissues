<?php

namespace GlpiPlugin\Gitlabissues;

use CommonITILActor;
use Document;
use Dropdown;
use Glpi\RichText\RichText;
use Html;
use Ticket;

/**
 * Builds the content of the GitLab issue from a GLPI ticket:
 * title, Markdown description (ticket data, converted rich text, inline images
 * and attachments uploaded to the GitLab project) and labels.
 */
final class IssueBuilder
{
    private const MAX_ATTACHMENTS      = 20;
    private const MAX_DESCRIPTION_CHARS = 900000; // GitLab limit is 1,048,576

    public function __construct(private readonly GitlabClient $client) {}

    /**
     * @return array{title: string, description: string, labels: string, confidential: bool, warnings: string[]}
     */
    public function build(Ticket $ticket, int|string $project, string $extra_labels, int $creator_id): array
    {
        $settings = Settings::all();
        $warnings = [];

        // 1. Attachments first: inline images of the description need their GitLab URL.
        $uploaded = [];
        if ($settings['upload_attachments'] === '1') {
            $uploaded = $this->uploadDocuments($ticket, $project, (int) $settings['max_attachment_mb'], $warnings);
        }

        // 2. Rich text -> Markdown
        $inline_docids = [];
        $converter = new HtmlToMarkdown(
            function (string $src, string $alt) use ($uploaded, &$inline_docids): ?string {
                if (preg_match('/docid=(\d+)/', html_entity_decode($src), $m) && isset($uploaded[(int) $m[1]])) {
                    $markdown = $uploaded[(int) $m[1]]['markdown'];
                    if (str_starts_with($markdown, '!')) {
                        $inline_docids[(int) $m[1]] = true;

                        return $markdown;
                    }
                }

                return null;
            },
            self::baseUrl()
        );
        $body = $converter->convert($this->contentAsHtml((string) $ticket->fields['content']));

        // 3. Description
        $parts   = [];
        $parts[] = $this->header($ticket, $creator_id, $settings['include_people'] === '1');
        $parts[] = "---";
        $parts[] = $body !== '' ? $body : '_' . __('(no description)', 'gitlabissues') . '_';

        $attachments = array_diff_key($uploaded, $inline_docids);
        if ($attachments !== []) {
            $lines = [];
            foreach ($attachments as $doc) {
                $lines[] = '- ' . $doc['markdown'];
            }
            $parts[] = "---\n**" . __('Attachments', 'gitlabissues') . "**\n\n" . implode("\n", $lines);
        }

        $description = implode("\n\n", $parts);
        if (mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION_CHARS)
                . "\n\n_" . __('(description truncated)', 'gitlabissues') . '_';
            $warnings[] = __('The ticket description was too long and has been truncated.', 'gitlabissues');
        }

        return [
            'title'        => $this->title($ticket, $settings['title_template']),
            'description'  => $description,
            'labels'       => $this->labels($settings['default_labels'], $extra_labels),
            'confidential' => $settings['confidential'] === '1',
            'warnings'     => $warnings,
        ];
    }

    // ------------------------------------------------------------------ pieces

    private function title(Ticket $ticket, string $template): string
    {
        $template = $template !== '' ? $template : Settings::DEFAULTS['title_template'];
        $title    = strtr($template, [
            '{id}'    => (string) $ticket->getID(),
            '{title}' => (string) $ticket->fields['name'],
        ]);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return mb_substr($title, 0, 255);
    }

    private function labels(string $default, string $extra): string
    {
        $labels = [];
        foreach (explode(',', $default . ',' . $extra) as $label) {
            $label = trim($label);
            if ($label !== '' && !isset($labels[mb_strtolower($label)])) {
                $labels[mb_strtolower($label)] = $label; // first spelling wins
            }
        }

        return implode(',', $labels);
    }

    private function header(Ticket $ticket, int $creator_id, bool $include_people): string
    {
        $id  = $ticket->getID();
        $url = self::ticketUrl($id);

        $creator = $creator_id > 0 ? getUserName($creator_id) : '';
        $intro   = $creator !== ''
            ? sprintf(__('Created from GLPI ticket %1$s by %2$s on %3$s', 'gitlabissues'), "[#$id]($url)", $this->plain($creator), Html::convDateTime(date('Y-m-d H:i:s')))
            : sprintf(__('Created from GLPI ticket %1$s on %2$s', 'gitlabissues'), "[#$id]($url)", Html::convDateTime(date('Y-m-d H:i:s')));

        $rows = [
            __('Ticket', 'gitlabissues')   => "[#$id]($url)",
            __('Opened on', 'gitlabissues') => Html::convDateTime($ticket->fields['date']),
            __('Status', 'gitlabissues')   => Ticket::getStatus((int) $ticket->fields['status']),
            __('Priority', 'gitlabissues') => Ticket::getPriorityName((int) $ticket->fields['priority']),
            __('Urgency', 'gitlabissues')  => Ticket::getUrgencyName((int) $ticket->fields['urgency']),
            __('Impact', 'gitlabissues')   => Ticket::getImpactName((int) $ticket->fields['impact']),
        ];

        $category = $this->dropdownName('glpi_itilcategories', (int) $ticket->fields['itilcategories_id']);
        if ($category !== '') {
            $rows[__('Category', 'gitlabissues')] = $category;
        }
        $entity = $this->dropdownName('glpi_entities', (int) $ticket->fields['entities_id']);
        if ($entity !== '') {
            $rows[__('Entity', 'gitlabissues')] = $entity;
        }

        if ($include_people) {
            $requesters = $this->actorNames($ticket, CommonITILActor::REQUESTER);
            if ($requesters !== '') {
                $rows[__('Requester', 'gitlabissues')] = $requesters;
            }
            $assignees = $this->actorNames($ticket, CommonITILActor::ASSIGN);
            if ($assignees !== '') {
                $rows[__('Assigned to', 'gitlabissues')] = $assignees;
            }
        }

        $table = "| | |\n|---|---|\n";
        foreach ($rows as $label => $value) {
            $is_link = str_starts_with($value, '[');
            $table  .= '| **' . $this->plain($label) . '** | ' . ($is_link ? $value : $this->plain($value)) . " |\n";
        }

        return '> ' . $intro . "\n\n" . rtrim($table);
    }

    private function actorNames(Ticket $ticket, int $type): string
    {
        $names = [];
        foreach ($ticket->getUsers($type) as $actor) {
            if ((int) $actor['users_id'] > 0) {
                $names[] = getUserName((int) $actor['users_id']);
            } elseif (!empty($actor['alternative_email'])) {
                $names[] = $actor['alternative_email'];
            }
        }
        foreach ($ticket->getGroups($type) as $group) {
            $name = $this->dropdownName('glpi_groups', (int) $group['groups_id']);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', array_filter($names));
    }

    private function dropdownName(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $name = html_entity_decode(strip_tags((string) Dropdown::getDropdownName($table, $id)), ENT_QUOTES | ENT_HTML5);

        return trim(str_replace("\u{a0}", ' ', $name));
    }

    /**
     * Text safe to put in a Markdown table cell.
     */
    private function plain(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return preg_replace('/([\\\\`*_\[\]<>~|])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * Ticket content is HTML in GLPI 11; legacy plain-text content is converted to HTML first.
     */
    private function contentAsHtml(string $content): string
    {
        if ($content === '') {
            return '';
        }
        if (!RichText::isRichTextHtmlContent($content)) {
            return '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        return RichText::getSafeHtml($content);
    }

    // ------------------------------------------------------------- attachments

    /**
     * Upload the documents linked to the ticket to the GitLab project.
     *
     * @param string[] $warnings
     *
     * @return array<int, array{markdown: string, name: string}> Indexed by GLPI document id
     */
    private function uploadDocuments(Ticket $ticket, int|string $project, int $max_mb, array &$warnings): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uploaded = [];
        $max_size = max(1, $max_mb) * 1024 * 1024;
        $count    = 0;

        $iterator = $DB->request([
            'SELECT'     => ['glpi_documents.id'],
            'FROM'       => 'glpi_documents_items',
            'INNER JOIN' => ['glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']]],
            'WHERE'      => ['glpi_documents_items.itemtype' => 'Ticket', 'glpi_documents_items.items_id' => $ticket->getID()],
            'GROUPBY'    => ['glpi_documents.id'],
            'ORDER'      => ['glpi_documents.id ASC'],
        ]);

        foreach ($iterator as $row) {
            $doc = new Document();
            if (!$doc->getFromDB((int) $row['id']) || empty($doc->fields['filepath'])) {
                continue; // link-only document (URL) or unreadable
            }
            $name = (string) ($doc->fields['filename'] ?: basename((string) $doc->fields['filepath']));
            $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];

            if ($count >= self::MAX_ATTACHMENTS) {
                $warnings[] = sprintf(__('Attachment not copied (limit of %d files): %s', 'gitlabissues'), self::MAX_ATTACHMENTS, $name);
                continue;
            }
            if (!is_readable($path)) {
                $warnings[] = sprintf(__('Attachment not found on disk: %s', 'gitlabissues'), $name);
                continue;
            }
            if (filesize($path) > $max_size) {
                $warnings[] = sprintf(__('Attachment not copied (larger than %d MB): %s', 'gitlabissues'), $max_mb, $name);
                continue;
            }

            try {
                $res = $this->client->uploadFile($project, $path, $name);
                $uploaded[(int) $doc->getID()] = ['markdown' => $res['markdown'], 'name' => $name];
                $count++;
            } catch (GitlabException $e) {
                // A failed upload must not prevent the issue creation, but the user is told.
                if ($e->isGlobal() && $e->getKind() !== GitlabException::SERVER) {
                    throw $e;
                }
                $warnings[] = sprintf(__('Attachment not copied (%1$s): %2$s', 'gitlabissues'), $e->getMessage(), $name);
            }
        }

        return $uploaded;
    }

    // -------------------------------------------------------------------- urls

    public static function baseUrl(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
    }

    public static function ticketUrl(int $id): string
    {
        // url_base already contains the GLPI root path, so the path is built without root_doc.
        return self::baseUrl() . '/front/ticket.form.php?id=' . $id;
    }
}
