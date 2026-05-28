import React, { useMemo, useState } from "react";
import {
  Callout,
  Card,
  CardBody,
  CardHeader,
  Code,
  Divider,
  Grid,
  H1,
  H2,
  H3,
  Pill,
  Row,
  Stack,
  Stat,
  Table,
  Text,
  useHostTheme,
} from "cursor/canvas";

type Severity = "high" | "medium" | "low" | "info";
type Area = "mail" | "feeds" | "scraper" | "lex" | "cron" | "scoring" | "retention" | "ui";

type Finding = {
  id: string;
  severity: Severity;
  area: Area;
  title: string;
  symptom: string;
  mechanism: string;
  location: string;
  visibility: string;
  mitigation: string;
};

const FINDINGS: Finding[] = [
  {
    id: "SF-01",
    severity: "high",
    area: "mail",
    title: "Gmail history advances after per-message fetch failures",
    symptom: "Individual messages never appear in the timeline; no UI error.",
    mechanism:
      "GmailApiInboxClient collects message IDs from history, then fetches each message. Failures are logged and skipped, but historyAdvanceId is still written to system_config at the end of the run. Those message IDs will not reappear in a future history slice.",
    location: "src/Core/Mail/GmailApiInboxClient.php (fetch loop + history cursor write)",
    visibility: "error_log only",
    mitigation:
      "Advance history only to the last fully processed history record, or retry failed IDs before advancing; surface a warn count in plugin_run_log / Diagnostics.",
  },
  {
    id: "SF-02",
    severity: "high",
    area: "feeds",
    title: "Chunked RSS/scraper cursor skips failed feeds until cycle wrap",
    symptom: "A broken feed produces no new items for hours or days while others refresh.",
    mechanism:
      "runRssChunkedOnce / runScraperChunkedOnce advance K_RSS_AFTER / K_SCRAPER_AFTER to max(feed id) in the batch regardless of per-feed failure. Failed feeds are not revisited until the cursor resets to 0 at end-of-cycle.",
    location: "src/Service/CoreRunner.php (chunked batch + cursor set)",
    visibility: "feeds.last_error when touchFeedFailure runs; easy to miss if scrape returns 0 items with success",
    mitigation:
      "Do not advance cursor past feeds that failed, or maintain a retry queue; distinguish zero-item success from fetch failure in touchFeed*.",
  },
  {
    id: "SF-03",
    severity: "medium",
    area: "scraper",
    title: "Scraper returns success with zero items when link pattern matches nothing",
    symptom: "Listing page configured but timeline stays empty; feed marked healthy.",
    mechanism:
      "fetchScraperFeedItems returns fatal_error=null and items=[] when collectMatchingLinkUrls finds no candidates (no warnings). CoreRunner calls touchFeedSuccess and batchFeeds reports ok.",
    location: "src/Core/Fetcher/ScraperFetchService.php; CoreRunner scraper ingest",
    visibility: "none in UI unless operator checks item count",
    mitigation:
      "Treat empty candidate set as warn; set last_error or a dedicated status when items=[] after a non-fatal listing fetch.",
  },
  {
    id: "SF-04",
    severity: "medium",
    area: "feeds",
    title: "Feed row ingest drops items without surfacing count",
    symptom: "RSS/scraper fetched N articles but timeline shows fewer; count in Diagnostics understates gap.",
    mechanism:
      "FeedItemRepository::upsertFeedItems silently continues on empty title, non-http link, or per-row SQL errors (savepoint rollback + error_log). Returned count is persisted rows only.",
    location: "src/Repository/FeedItemRepository.php",
    visibility: "error_log per skipped row",
    mitigation:
      "Return {inserted, skipped, reasons} and fold into PluginRunResult message or source_log.",
  },
  {
    id: "SF-05",
    severity: "medium",
    area: "mail",
    title: "Hosted newsletter body hydration capped at 15 per batch",
    symptom: "New emails ingested with teaser-only body until a later run (or never if backlog persists).",
    mechanism:
      "EmailIngestRepository::MAX_HOSTED_HYDRATE_PER_BATCH limits web-view fetches per upsert batch; remaining rows skip hydration for that run.",
    location: "src/Repository/EmailIngestRepository.php",
    visibility: "none",
    mitigation:
      "Log hydrated vs deferred counts; optional second-pass cron job for deferred rows.",
  },
  {
    id: "SF-06",
    severity: "medium",
    area: "mail",
    title: "Legacy IMAP defaults to UNSEEN and max 50 messages",
    symptom: "Already-read mail never ingested; bursts beyond 50 UID window never seen.",
    mechanism:
      "ImapMailFetchService uses mail_search_criteria default UNSEEN and takes the newest tail of at most 500-capped mail_max_messages (default 50).",
    location: "src/Core/Fetcher/ImapMailFetchService.php",
    visibility: "config-only; easy to misconfigure",
    mitigation:
      "Document in Settings → Mail; warn when criteria is UNSEEN and last run count is 0 repeatedly.",
  },
  {
    id: "SF-07",
    severity: "medium",
    area: "cron",
    title: "Overlapping refresh_cron exits 0 with no plugin work",
    symptom: "Expected ingest tick does nothing; monitoring shows success.",
    mechanism:
      "refresh_cron.php calls tryAcquireRefreshCron(); on failure logs one line and exit(0). No plugin_run_log row.",
    location: "refresh_cron.php; src/Repository/CronMutexRepository.php",
    visibility: "logs/refresh_cron.log only",
    mitigation:
      "Exit code 0 is fine for overlap, but emit a metric or plugin_run_log pseudo-row; alert on consecutive skips.",
  },
  {
    id: "SF-08",
    severity: "medium",
    area: "cron",
    title: "Throttle skips are not written to plugin_run_log",
    symptom: "Diagnostics shows stale last successful run while cron did nothing this tick.",
    mechanism:
      "PluginRunResult::throttleSkipped sets persistToPluginRunLog=false. CoreRunner returns early without record().",
    location: "src/Service/PluginRunResult.php; CoreRunner throttled paths",
    visibility: "cron stdout if watched; absent from Diagnostics table",
    mitigation:
      "Optional lightweight log row status=skipped_throttle or expose last_skip_at in Diagnostics.",
  },
  {
    id: "SF-09",
    severity: "medium",
    area: "scoring",
    title: "Recipe rescore after refresh is best-effort",
    symptom: "New entries appear on timeline without Magnitu/recipe scores until later cron or manual rescore.",
    mechanism:
      "RefreshAllService::recipeRescoreAfterIngest catches all throwables and error_logs; does not change plugin status.",
    location: "src/Service/RefreshAllService.php; ScoringService::rescoreStoredRecipeBestEffortForRepos",
    visibility: "error_log on failure only",
    mitigation:
      "Append rescore stats to refresh flash message; fail soft with visible warn in Diagnostics.",
  },
  {
    id: "SF-10",
    severity: "low",
    area: "feeds",
    title: "RSS ingest caps at 200 items per feed per run",
    symptom: "Older items in a large feed never stored if not in the latest 200 SimplePie items.",
    mechanism:
      "RssFetchService iterates get_items(0, 200) only.",
    location: "src/Core/Fetcher/RssFetchService.php",
    visibility: "by design",
    mitigation: "Document; acceptable for news feeds, risky for archive-style feeds.",
  },
  {
    id: "SF-11",
    severity: "low",
    area: "scraper",
    title: "Scraper production cap: 20 articles, 50 link scan",
    symptom: "Listing pages with many articles only partially ingested each run.",
    mechanism:
      "PRODUCTION_MAX_ARTICLES=20; LINKS_SCAN_CAP=50; failed article URLs only in warnings → error_log.",
    location: "src/Core/Fetcher/ScraperFetchService.php",
    visibility: "error_log warnings",
    mitigation: "Surface warning count in plugin_run_log message for core:scraper.",
  },
  {
    id: "SF-12",
    severity: "low",
    area: "mail",
    title: "Gmail safety cap drops backlog message IDs",
    symptom: "Large inbox catch-up leaves mail for later runs (intended) but easy to mistake for loss.",
    mechanism:
      "After history collection, array_slice enforces maxMessages; error_log notes dropped count; cursor may still advance.",
    location: "src/Core/Mail/GmailApiInboxClient.php",
    visibility: "error_log",
    mitigation: "Already partially safe; align cursor advance with actually fetched IDs only.",
  },
  {
    id: "SF-13",
    severity: "low",
    area: "mail",
    title: "Gmail history expiry falls back to 7–30 day bootstrap",
    symptom: "Mail older than catch-up window never ingested without manual catch-up action.",
    mechanism:
      "Invalid startHistoryId triggers bootstrapMessageIds with GMAIL_CATCHUP_DAYS (default 7, max 30).",
    location: "src/Core/Mail/GmailApiInboxClient.php",
    visibility: "error_log once",
    mitigation: "Admin action: catch-up fetch; document in Mail settings.",
  },
  {
    id: "SF-14",
    severity: "low",
    area: "lex",
    title: "Lex plugin rows skipped per-row without batch failure",
    symptom: "Plugin reports ok with count < fetched rows; missing lex items.",
    mechanism:
      "LexItemRepository::upsertBatch uses savepoints; bad rows error_log and continue.",
    location: "src/Repository/LexItemRepository.php",
    visibility: "error_log",
    mitigation: "Warn status when skipped > 0; same pattern as feeds.",
  },
  {
    id: "SF-15",
    severity: "info",
    area: "retention",
    title: "Retention cron deletes aged rows by policy",
    symptom: "Entries disappear after N days unless favourited / high score / labelled.",
    mechanism:
      "RetentionService::pruneAll at end of refresh_cron; failures logged but exit code unchanged.",
    location: "src/Service/RetentionService.php; refresh_cron.php",
    visibility: "cron log + Settings preview",
    mitigation: "Intentional; ensure operators understand keep predicates.",
  },
  {
    id: "SF-16",
    severity: "info",
    area: "cron",
    title: "Web refresh skips Lex plugins on timeline Refresh",
    symptom: "Lex sources stale unless Diagnostics refresh or cron runs.",
    mechanism:
      "refresh_all with skipLexPlugins=true from timeline; message appended to flash only.",
    location: "src/Controller/DiagnosticsController.php",
    visibility: "flash message when user reads it",
    mitigation: "Documented; not silent if user triggers timeline refresh.",
  },
  {
    id: "SF-17",
    severity: "info",
    area: "ui",
    title: "Chunked web refresh pauses mid-cycle (120s budget)",
    symptom: "Refresh all appears successful but many feeds not yet processed this cycle.",
    mechanism:
      "runChunkedCoreWithBudget returns ok with persist=false and message about time budget; cursor retained.",
    location: "src/Service/CoreRunner.php",
    visibility: "success flash may omit partial progress unless message read",
    mitigation: "Always persist final paused row or show progress bar in Diagnostics.",
  },
];

const SEVERITY_ORDER: Severity[] = ["high", "medium", "low", "info"];

const SEVERITY_LABEL: Record<Severity, string> = {
  high: "High",
  medium: "Medium",
  low: "Low",
  info: "Info",
};

function severityTone(s: Severity): "danger" | "warning" | "neutral" | "info" {
  if (s === "high") return "danger";
  if (s === "medium") return "warning";
  if (s === "low") return "neutral";
  return "info";
}

function countBySeverity(sev: Severity): number {
  return FINDINGS.filter((f) => f.severity === sev).length;
}

export default function SilentFailureAuditCanvas() {
  const theme = useHostTheme();
  const [filter, setFilter] = useState<"all" | Severity>("all");
  const [areaFilter, setAreaFilter] = useState<"all" | Area>("all");

  const areas = useMemo(() => {
    const set = new Set<Area>();
    FINDINGS.forEach((f) => set.add(f.area));
    return Array.from(set).sort();
  }, []);

  const filtered = useMemo(() => {
    return FINDINGS.filter((f) => {
      if (filter !== "all" && f.severity !== filter) return false;
      if (areaFilter !== "all" && f.area !== areaFilter) return false;
      return true;
    }).sort(
      (a, b) =>
        SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity)
    );
  }, [filter, areaFilter]);

  const recommendedBuildOrder = [
    "SF-01 Gmail cursor vs failed message IDs",
    "SF-02 Chunk cursor vs per-feed failure",
    "SF-03 Scraper empty-match as warn",
    "SF-04 Ingest skip counters in plugin_run_log",
    "SF-08 Throttle visibility in Diagnostics",
  ];

  return (
    <Stack gap={20} style={{ padding: 20, minHeight: "100%", background: theme.bg.editor }}>
      <Stack gap={6}>
        <H1>Silent failure & dropped-entry audit</H1>
        <Text tone="secondary">
          Seismo 0.6 ingest pipeline review — findings only (no fixes applied). Source: static code audit, May 2026.
        </Text>
      </Stack>

      <Grid columns={4} gap={12}>
        <Stat value={String(countBySeverity("high"))} label="High" tone="danger" />
        <Stat value={String(countBySeverity("medium"))} label="Medium" tone="warning" />
        <Stat value={String(countBySeverity("low"))} label="Low" />
        <Stat value={String(countBySeverity("info"))} label="Informational" tone="info" />
      </Grid>

      <Callout tone="warning" title="Highest-impact gaps">
        The two issues most likely to cause entries that never appear (with little UI signal) are Gmail history advancing past failed message fetches (SF-01) and chunked feed cursors skipping failed sources until a full cycle completes (SF-02).
      </Callout>

      <Card>
        <CardHeader>Suggested build order (if you implement fixes next)</CardHeader>
        <CardBody>
          <Stack gap={6}>
            {recommendedBuildOrder.map((line) => (
              <Text key={line} size="small">
                {line}
              </Text>
            ))}
          </Stack>
        </CardBody>
      </Card>

      <Row gap={8} wrap>
        <Pill active={filter === "all"} onClick={() => setFilter("all")}>
          All severities
        </Pill>
        {SEVERITY_ORDER.map((s) => (
          <Pill
            key={s}
            active={filter === s}
            tone={severityTone(s)}
            onClick={() => setFilter(s)}
          >
            {SEVERITY_LABEL[s]}
          </Pill>
        ))}
      </Row>

      <Row gap={8} wrap>
        <Pill active={areaFilter === "all"} onClick={() => setAreaFilter("all")}>
          All areas
        </Pill>
        {areas.map((a) => (
          <Pill key={a} active={areaFilter === a} onClick={() => setAreaFilter(a)}>
            {a}
          </Pill>
        ))}
      </Row>

      <H2>Findings ({filtered.length})</H2>
      <Table
        headers={["ID", "Sev.", "Area", "Title", "Symptom", "Where it hides"]}
        rows={filtered.map((f) => [
          <Code>{f.id}</Code>,
          <Pill tone={severityTone(f.severity)} active size="sm">
            {SEVERITY_LABEL[f.severity]}
          </Pill>,
          f.area,
          <Text weight="semibold">{f.title}</Text>,
          <Text tone="secondary" size="small">
            {f.symptom}
          </Text>,
          <Text tone="tertiary" size="small">
            {f.visibility}
          </Text>,
        ])}
        columnAlign={["left", "center", "left", "left", "left", "left"]}
        striped
        stickyHeader
      />

      {filtered.map((f) => (
        <Card key={f.id} collapsible defaultOpen={f.severity === "high"}>
          <CardHeader
            trailing={
              <Pill tone={severityTone(f.severity)} active size="sm">
                {f.id}
              </Pill>
            }
          >
            {f.title}
          </CardHeader>
          <CardBody>
            <Stack gap={10}>
              <Row gap={16} wrap>
                <Text size="small" tone="secondary">
                  Area: {f.area}
                </Text>
                <Text size="small" tone="secondary">
                  Visibility: {f.visibility}
                </Text>
              </Row>
              <div>
                <H3>Mechanism</H3>
                <Text tone="secondary">{f.mechanism}</Text>
              </div>
              <div>
                <H3>Code location</H3>
                <Code>{f.location}</Code>
              </div>
              <div>
                <H3>Recommended mitigation (before/while building)</H3>
                <Text>{f.mitigation}</Text>
              </div>
            </Stack>
          </CardBody>
        </Card>
      ))}

      <Divider />

      <H2>What already works well</H2>
      <Table
        headers={["Control", "Behavior"]}
        rows={[
          [
            "Per-feed try/catch",
            "RSS/scraper/parl press log failures and set feeds.last_error via touchFeedFailure",
          ],
          [
            "Cron mutex",
            "Prevents corrupting chunked cursors from overlapping refresh (skipped tick is logged)",
          ],
          [
            "GmailHistoryIngestCap",
            "Avoids advancing history past a partially consumed oversized history record (unit tested)",
          ],
          [
            "Email/lex row savepoints",
            "One bad row does not abort entire batch (but skips are log-only today)",
          ],
          [
            "Source health UI",
            "Settings → Diagnostics lists broken/stale feeds when last_error or staleness thresholds hit",
          ],
          [
            "Retention preview",
            "dryRunPrune matches prune WHERE — deletions are policy-visible, not accidental bugs",
          ],
        ]}
        striped
      />

      <Text size="small" tone="tertiary" style={{ textAlign: "center" }}>
        Source: Seismo 0.6 codebase static audit · May 2026 · Report-only (no code changes in this deliverable)
      </Text>
    </Stack>
  );
}
