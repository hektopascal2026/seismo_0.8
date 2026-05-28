import React, { useState } from "react";
import {
  Button,
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
  Link,
  mergeStyle,
  Pill,
  Row,
  Spacer,
  Stack,
  Stat,
  Table,
  Text,
  useHostTheme,
} from "cursor/canvas";

export default function SecurityAuditCanvas() {
  const theme = useHostTheme();
  const [activeTab, setActiveTab] = useState<"all" | "vulnerabilities" | "robustness">("all");

  const vulnerabilities = [
    {
      id: "VULN-001",
      name: "Unauthenticated Setup Overwrite (Host Takeover)",
      severity: "critical",
      file: "src/Controller/SetupController.php",
      impact: "If the database is temporarily down or inaccessible (e.g., restarts, lockout, or load spikes), 'hasDbConnection()' returns false. This completely disables AuthGate protection on the '?action=configuration' route, permitting any unauthenticated visitor to POST new database configurations and hijack the Seismo instance to point to their own malicious server, leading to admin takeover and session theft.",
      code: `// src/Controller/SetupController.php - show() & handlePost()
if (hasDbConnection()) {
    header('Location: ' . getBasePath() . '/index.php?action=index', true, 303);
    exit;
}`,
      remediation: "Block any configuration/setup operations if 'config.local.php' exists on disk, regardless of the DB connection status. Modify hasDbConnection() or add a check: if (is_file(SEISMO_ROOT . '/config.local.php')) { ... block/redirect ... }.",
    },
    {
      id: "VULN-002",
      name: "Server-Side Request Forgery (SSRF) in Web Scraper",
      severity: "low",
      file: "src/Core/Fetcher/ScraperFetchService.php",
      impact: "The unified scraper accepts arbitrary URLs and fetches them via BaseClient without inspecting the IP address or hostname. An authenticated admin could fetch internal metadata endpoints (like AWS metadata, open internal services, or loopback ports on 127.0.0.1) that are not exposed to the public internet.",
      code: `// src/Core/Fetcher/ScraperFetchService.php - isNavigableHttpUrl()
private function isNavigableHttpUrl(string $url): bool
{
    $u = trim($url);
    if ($u === '' || $u === '#') {
        return false;
    }
    return (bool)preg_match('#^https?://#i', $u);
}`,
      remediation: "Resolve hostnames and validate that the target IP does not resolve to loopback (127.0.0.0/8), private ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), or link-local (169.254.169.254) before issuing Curl/Stream fetches.",
    },
  ];

  const robustnessChecks = [
    {
      feature: "SQL Injection Protection",
      status: "Robust",
      details: "Hard layer boundaries are strictly maintained. Direct SQL queries reside purely in 'src/Repository/' directories. All user input is bound via PDO parameters. Table names are dynamically constructed using 'entryTable()', which wraps and namespaces tables safely (no user interpolation).",
    },
    {
      feature: "Session & CSRF Protection",
      status: "Robust",
      details: "Mutating requests are guarded by a single-use rotating token managed by 'CsrfToken'. Tokens are securely bound to the session and rotated upon every successful POST to mitigate token reuse attacks.",
    },
    {
      feature: "Ingest Concurrency & Mutexes",
      status: "Robust",
      details: "To prevent racing between Cron CLI ticks and browser refreshes, 'CronMutexRepository' leverages MariaDB advisory locks (GET_LOCK(..., 0)). Because timeout is set to 0, concurrent lock attempts fail immediately rather than backing up the process queue.",
    },
    {
      feature: "IMAP Mail Fetch Security",
      status: "Robust",
      details: "The 'mail_imap_mailbox' string is strictly pulled from the database config and is only editable by an authenticated admin, reducing risk of shell/argument injections through PHP's 'imap_open'.",
    },
    {
      feature: "Resource Allocation Floor",
      status: "Robust",
      details: "Memory floors (512M) are set programmatically in bootstrap, and standard FPM/CLI execution time limits (300s) are applied to ensure heavy feeds or large email parses do not exhaust memory or hang indefinitely.",
    }
  ];

  return (
    <Stack gap={20} style={{ padding: 20, minHeight: "100%", background: theme.bg.editor }}>
      <Row justify="space-between" align="center">
        <Stack gap={4}>
          <H1>Seismo 0.6 Security & Critical Bug Audit</H1>
          <Text tone="secondary">
            Standalone architectural security review for the Seismo mothership & path satellites.
          </Text>
        </Stack>
        <Pill tone="info" active>
          v0.7.6 Audit
        </Pill>
      </Row>

      <Divider />

      <Grid columns={3} gap={16}>
        <Stat value="1" label="Critical Vulnerability" tone="danger" />
        <Stat value="1" label="Low Vulnerability" tone="warning" />
        <Stat value="5" label="Robust Security Systems" tone="success" />
      </Grid>

      <Callout tone="danger" title="Critical Hazard Detected" icon="⚠️">
        An unauthenticated setup overwrite flaw can let attackers take over a live instance when the database goes offline. We highly recommend fixing this immediately.
      </Callout>

      <Row gap={8}>
        <Pill active={activeTab === "all"} onClick={() => setActiveTab("all")}>
          All Audits
        </Pill>
        <Pill active={activeTab === "vulnerabilities"} onClick={() => setActiveTab("vulnerabilities")} tone="danger">
          Vulnerabilities
        </Pill>
        <Pill active={activeTab === "robustness"} onClick={() => setActiveTab("robustness")} tone="success">
          Robust Features
        </Pill>
      </Row>

      {(activeTab === "all" || activeTab === "vulnerabilities") && (
        <Stack gap={16}>
          <H2>Identified Security Vulnerabilities</H2>
          {vulnerabilities.map((v) => (
            <Card key={v.id} variant="default">
              <CardHeader
                trailing={
                  <Pill tone={v.severity === "critical" ? "danger" : "warning"} active>
                    {v.severity.toUpperCase()}
                  </Pill>
                }
              >
                {v.id}: {v.name}
              </CardHeader>
              <CardBody>
                <Stack gap={12}>
                  <Row gap={8} align="center">
                    <Text weight="semibold">File Location:</Text>
                    <Code>{v.file}</Code>
                  </Row>
                  <Text tone="secondary">{v.impact}</Text>
                  <H3>Vulnerable Code Segment:</H3>
                  <Code>{v.code}</Code>
                  <H3>Required Remediation:</H3>
                  <Text tone="primary" weight="medium" style={{ color: theme.accent.primary }}>
                    {v.remediation}
                  </Text>
                </Stack>
              </CardBody>
            </Card>
          ))}
        </Stack>
      )}

      {(activeTab === "all" || activeTab === "robustness") && (
        <Stack gap={16}>
          <H2>Robustness & Security Best Practices Verified</H2>
          <Table
            headers={["Security Component", "Status", "Technical Verification Details"]}
            rows={robustnessChecks.map((c) => [
              <Text weight="semibold">{c.feature}</Text>,
              <Pill tone="success" active size="sm">
                {c.status}
              </Pill>,
              <Text tone="secondary" size="small">
                {c.details}
              </Text>,
            ])}
            columnAlign={["left", "center", "left"]}
            striped
          />
        </Stack>
      )}

      <Divider />

      <Text size="small" tone="tertiary" style={{ textAlign: "center" }}>
        Source: Automated Seismo Code Auditing · May 2026
      </Text>
    </Stack>
  );
}
