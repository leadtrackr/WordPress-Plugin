import { Tabs, TabsContent, TabsList, TabsTrigger } from "./components/ui/tabs";
import logoSvg from "./assets/logo.svg";
import { Input } from "./components/ui/input";
import { Button } from "./components/ui/button";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "./components/ui/use-toast";
import {
  ToggleField,
  EmptyState,
  ExternalLink,
  Field,
  Section,
} from "./components/ui/section";
import { Switch } from "./components/ui/switch";
import { links } from "./links";

interface LeadTrackrForm {
  id: string;
  title?: string;
  sendToLeadTrackr?: boolean;
  customTitle?: string;
}

interface UtmParams {
  sourceParam: string;
  mediumParam: string;
  campaignParam: string;
  contentParam: string;
  termParam: string;
}

interface LeadBotSettings {
  enabled: boolean;
  companyName: string;
  agentName: string;
  agentPhoto: string;
  greeting: string;
  phone: string;
  whatsapp: string;
  launcher: boolean;
  teaser: boolean;
  whatsappInterceptor: boolean;
  whatsappPhoneQuestion: boolean;
  callTracking: boolean;
  position: "right" | "left";
  offsetBottom: number;
  offsetSide: number;
  language: "auto" | "nl" | "en";
  responseTimeText: string;
  themePrimary: string;
  themePrimaryHover: string;
  themeRadius: number;
}

interface BuilderData {
  enabled: boolean;
  forms: LeadTrackrForm[];
  trackAll: boolean;
}

declare global {
  interface Window {
    wpData: {
      apiUrl: string;
      nonce: string;
      projectId: string;
      apiTokenSet: boolean;
      apiTokenRequired: boolean;
      channelFlowEnabled: boolean;
      utmParams: UtmParams;
      gravityForms: BuilderData;
      cf7: BuilderData;
      elementor: BuilderData;
      wpforms: BuilderData;
      fluentForms: BuilderData;
      divi: {
        enabled: boolean;
        processContactForm: boolean;
      };
      leadbot: LeadBotSettings;
      leadbotSrc: string;
      leadbotPreviewEndpoint: string;
    };
  }
}

/**
 * One definition per form builder, so the tab strip, the empty states and the
 * save calls all read from the same place instead of repeating the mapping.
 */
const BUILDERS = [
  { tab: "gravity-forms", label: "Gravity Forms", key: "gravityForms" },
  { tab: "contact-form-7", label: "Contact Form 7", key: "cf7" },
  { tab: "elementor", label: "Elementor", key: "elementor" },
  { tab: "wpforms", label: "WPForms", key: "wpforms" },
  { tab: "fluent-forms", label: "Fluent Forms", key: "fluentForms" },
] as const;

const DEFAULT_UTM: UtmParams = {
  sourceParam: "utm_source",
  mediumParam: "utm_medium",
  campaignParam: "utm_campaign",
  contentParam: "utm_content",
  termParam: "utm_term",
};

const UTM_FIELDS: Array<{ key: keyof UtmParams; label: string }> = [
  { key: "sourceParam", label: "Source parameter" },
  { key: "mediumParam", label: "Medium parameter" },
  { key: "campaignParam", label: "Campaign parameter" },
  { key: "contentParam", label: "Content parameter" },
  { key: "termParam", label: "Term parameter" },
];

/**
 * WordPress has more than one way of handing a boolean to JavaScript, and a
 * "1" where a true was expected is enough to make the page believe it has
 * unsaved changes. Everything from wpData goes through here first.
 */
function bool(value: unknown): boolean {
  return value === true || value === 1 || value === "1";
}

function builderOf(tab: string) {
  return BUILDERS.find((b) => b.tab === tab);
}

function isBuilderEnabled(tab: string): boolean {
  if (tab === "divi") return window.wpData.divi.enabled;
  const builder = builderOf(tab);
  return builder ? window.wpData[builder.key].enabled : true;
}

function App() {
  const [activeTab, setActiveTab] = useState<string>("general");
  const [projectId, setProjectId] = useState<string>("");
  const [apiToken, setApiToken] = useState<string>("");
  const [apiTokenSet, setApiTokenSet] = useState<boolean>(bool(window.wpData.apiTokenSet));
  const [channelFlowEnabled, setChannelFlowEnabled] = useState<boolean>(
    bool(window.wpData.channelFlowEnabled)
  );
  const [utmParams, setUtmParams] = useState<UtmParams>(window.wpData.utmParams ?? DEFAULT_UTM);
  const [showCustomUtm, setShowCustomUtm] = useState<boolean>(false);
  const normaliseLeadbot = (raw: LeadBotSettings): LeadBotSettings => ({
    ...raw,
    enabled: bool(raw.enabled),
    launcher: bool(raw.launcher),
    teaser: bool(raw.teaser),
    whatsappInterceptor: bool(raw.whatsappInterceptor),
    whatsappPhoneQuestion: bool(raw.whatsappPhoneQuestion),
    callTracking: bool(raw.callTracking),
    offsetBottom: Number(raw.offsetBottom),
    offsetSide: Number(raw.offsetSide),
    themeRadius: Number(raw.themeRadius),
  });
  const [leadbot, setLeadbot] = useState<LeadBotSettings>(() =>
    normaliseLeadbot(window.wpData.leadbot)
  );
  const [loading, setLoading] = useState<boolean>(false);
  // Snapshots of what is actually persisted, so the page can tell the admin
  // there is something left to save instead of quietly losing their edits.
  const [savedGeneral, setSavedGeneral] = useState(() => ({
    projectId: window.wpData.projectId || "",
    channelFlowEnabled: bool(window.wpData.channelFlowEnabled),
    utmParams: window.wpData.utmParams ?? DEFAULT_UTM,
  }));
  const [savedLeadbot, setSavedLeadbot] = useState<LeadBotSettings>(() =>
    normaliseLeadbot(window.wpData.leadbot)
  );
  const [formsDirty, setFormsDirty] = useState(false);
  // window.wpData is the state of things when the page was rendered; it never
  // changes again. Keeping the builder data here instead means switching tabs
  // shows what was just saved rather than resetting to that snapshot.
  const [builderData, setBuilderData] = useState<Record<string, BuilderData>>(() => ({
    gravityForms: window.wpData.gravityForms,
    cf7: window.wpData.cf7,
    elementor: window.wpData.elementor,
    wpforms: window.wpData.wpforms,
    fluentForms: window.wpData.fluentForms,
  }));
  const [forms, setForms] = useState<LeadTrackrForm[]>([]);
  const [trackAll, setTrackAll] = useState<boolean>(false);
  const [diviProcessContactForm, setDiviProcessContactForm] = useState<boolean>(
    window.wpData.divi.processContactForm
  );

  useEffect(() => {
    if (typeof window.wpData === "undefined") {
      console.error("wpData is not defined");
      return;
    }
    if (window.wpData.projectId) {
      setProjectId(window.wpData.projectId);
    }
  }, []);

  useEffect(() => {
    const params = window.wpData.utmParams;
    if (
      params &&
      UTM_FIELDS.some(({ key }) => params[key] !== DEFAULT_UTM[key])
    ) {
      setShowCustomUtm(true);
    }
  }, []);

  useEffect(() => {
    const builder = builderOf(activeTab);
    if (builder) {
      setForms(builderData[builder.key].forms);
      setTrackAll(builderData[builder.key].trackAll);
    }
    if (activeTab === "divi") {
      setForms([]);
    }
    setFormsDirty(false);
  }, [activeTab, builderData]);

  // The LeadBot posts client-side and only needs a project. The connection as a
  // whole is not complete until the token is there too.
  const hasProject = Boolean(projectId);
  // Sites that upgraded into this version keep working without a token; only
  // new installs must supply one.
  const tokenRequired = bool(window.wpData.apiTokenRequired);
  const connected = hasProject && (apiTokenSet || !tokenRequired);

  const discardChanges = () => {
    if (activeTab === "leadbot") {
      setLeadbot(savedLeadbot);
      return;
    }
    if (activeTab === "general") {
      setProjectId(savedGeneral.projectId);
      setChannelFlowEnabled(savedGeneral.channelFlowEnabled);
      setUtmParams(savedGeneral.utmParams);
      setApiToken("");
      return;
    }
    window.location.reload();
  };

  const generalDirty =
    JSON.stringify({ projectId, channelFlowEnabled, utmParams }) !== JSON.stringify(savedGeneral) ||
    apiToken !== "";
  const leadbotDirty = JSON.stringify(leadbot) !== JSON.stringify(savedLeadbot);
  const dirty =
    activeTab === "general"
      ? generalDirty
      : activeTab === "leadbot"
      ? leadbotDirty
      : connected && formsDirty;

  // Closing or reloading with unsaved changes now costs a browser confirm
  // rather than the edits silently disappearing.
  // Set just before a reload the page triggers itself, so the visitor is not
  // asked to confirm leaving a page that is deliberately reloading.
  const reloadingOnPurpose = useRef(false);

  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => {
      if (reloadingOnPurpose.current) return;
      event.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  // The LeadBot boots once per document and guards against a second boot, so a
  // live preview needs a fresh document per change — hence an iframe that is
  // rewritten instead of a component that re-renders. Debounced so typing does
  // not reload it on every keystroke.
  const [previewOf, setPreviewOf] = useState<LeadBotSettings>(leadbot);
  useEffect(() => {
    const timer = setTimeout(() => setPreviewOf(leadbot), 400);
    return () => clearTimeout(timer);
  }, [leadbot]);

  const previewDoc = useMemo(() => {
    const config: Record<string, unknown> = {
      projectId: projectId || "preview",
      // Never the real API: submitting the preview form must not create a lead.
      endpoint: window.wpData.leadbotPreviewEndpoint,
    };
    const copy = (key: keyof LeadBotSettings, as = key as string) => {
      const value = previewOf[key];
      if (value !== "" && value !== undefined) config[as] = value;
    };
    (["companyName", "agentName", "agentPhoto", "greeting", "phone", "whatsapp"] as const).forEach(
      (key) => copy(key)
    );
    config.launcher = previewOf.launcher;
    config.teaser = previewOf.teaser;
    config.whatsappPhoneQuestion = previewOf.whatsappPhoneQuestion;
    config.position = previewOf.position;
    config.offset = { bottom: previewOf.offsetBottom, side: previewOf.offsetSide };
    if (previewOf.language !== "auto") config.language = previewOf.language;
    if (previewOf.responseTimeText) config.responseTimeText = previewOf.responseTimeText;
    const theme: Record<string, unknown> = { radius: previewOf.themeRadius };
    if (previewOf.themePrimary) theme.primary = previewOf.themePrimary;
    if (previewOf.themePrimaryHover) theme.primaryHover = previewOf.themePrimaryHover;
    config.theme = theme;

    return `<!doctype html><html lang="${previewOf.language === "auto" ? "nl" : previewOf.language}">
<head><meta charset="utf-8"><style>
html,body{height:100%;margin:0;background:#f0f0f1;font-family:system-ui,sans-serif}
.hint{padding:16px 20px;color:#8c8f94;font-size:12px;line-height:1.6}
</style></head>
<body><p class="hint">Preview — this is a sandbox. Anything you submit here is discarded.</p>
<script>window.ltLeadBotConfig=${JSON.stringify(config)};<\/script>
<script src="${window.wpData.leadbotSrc}" async><\/script>
</body></html>`;
  }, [previewOf, projectId]);

  const notify = (ok: boolean, what: string) =>
    toast(
      ok
        ? { title: `${what} saved`, description: "Your changes are live.", variant: "default" }
        : {
            title: `Could not save ${what.toLowerCase()}`,
            description: "Something went wrong. Please try again.",
            variant: "destructive",
          }
    );

  const post = (path: string, body: unknown) =>
    fetch(`${window.wpData.apiUrl}/${path}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        // WordPress only honours the login cookie on the REST API when this
        // nonce comes with it. Without the header every save is rejected as
        // rest_forbidden, no matter who is logged in.
        "X-WP-Nonce": window.wpData.nonce,
      },
      credentials: "include",
      body: JSON.stringify(body),
    });

  const onSaveGeneral = async () => {
    setLoading(true);

    const requests = [
      post("project-id", { project_id: projectId }),
      post("channelflow-settings", { enabled: channelFlowEnabled, utmParams }),
    ];

    // The stored token is never sent to the browser, so the field starts empty
    // even when one is saved. Only post it when something was actually typed,
    // otherwise saving any other setting would wipe the token.
    if (apiToken) {
      requests.push(post("api-token", { api_token: apiToken }));
    }

    const responses = await Promise.all(requests);
    const ok = responses.every((response) => response.ok);
    if (ok) {
      if (apiToken) setApiTokenSet(true);
      setApiToken("");
      setSavedGeneral({ projectId, channelFlowEnabled, utmParams });
    }
    notify(ok, "Settings");
    setLoading(false);
  };

  const onSaveLeadBot = async () => {
    setLoading(true);
    const response = await post("leadbot", { leadbot });
    if (response.ok) setSavedLeadbot(leadbot);
    notify(response.ok, "LeadBot settings");
    setLoading(false);
  };

  const onSaveForms = async () => {
    setLoading(true);

    const data =
      activeTab === "divi"
        ? { processContactForm: diviProcessContactForm }
        : {
            forms: structuredClone(forms).map((f) => {
              delete f.title;
              return f;
            }),
          };

    const requests: Promise<Response>[] = [post(activeTab, data)];

    if (activeTab !== "divi") {
      requests.push(post("track-all", { builder: activeTab, enabled: trackAll }));
    }

    const responses = await Promise.all(requests);
    const ok = responses.every((r) => r.ok);
    if (ok) {
      setFormsDirty(false);
      const builder = builderOf(activeTab);
      if (builder) {
        setBuilderData((current) => ({
          ...current,
          [builder.key]: { ...current[builder.key], forms, trackAll },
        }));
      }
    }
    notify(ok, "Forms");
    setLoading(false);
  };

  const setBot = <K extends keyof LeadBotSettings>(key: K, value: LeadBotSettings[K]) =>
    setLeadbot((current) => ({ ...current, [key]: value }));


  const formsContent = (() => {
    // Turning a form on before the connection is complete would send every
    // submission into a rejected request, so the choice is not offered yet.
    if (!connected) {
      return (
        <EmptyState title="Finish the connection first">
          {tokenRequired
            ? "Add your Project ID and API token under General before choosing which forms to track. Until both are set, nothing can be sent to LeadTrackr."
            : "Add your Project ID under General before choosing which forms to track. Nothing can be sent to LeadTrackr until it is set."}
          <span className="mt-3 block">
            <Button variant="outline" onClick={() => setActiveTab("general")}>
              Go to General
            </Button>
          </span>
        </EmptyState>
      );
    }

    if (!isBuilderEnabled(activeTab)) {
      const label = activeTab === "divi" ? "Divi" : builderOf(activeTab)?.label;
      return (
        <EmptyState title={`${label} was not found on this site`}>
          Install and activate {label} and this tab will fill itself with your forms. Nothing
          is broken — there is simply nothing to configure yet.
        </EmptyState>
      );
    }

    if (activeTab === "divi") {
      return (
        <Section
          title="Divi Contact Form"
          description="Divi has no per-form settings, so this is a single switch for every Divi contact form on the site."
        >
          <ToggleField
            id="process-contact-form"
            label="Send Divi Contact Form submissions to LeadTrackr"
            checked={diviProcessContactForm}
            onChange={(checked) => {
              setFormsDirty(true);
              setDiviProcessContactForm(checked);
            }}
          />
        </Section>
      );
    }

    const label = builderOf(activeTab)?.label;

    return (
      <Section
        title={`${label} forms`}
        description="Pick which forms create a lead in LeadTrackr. A custom name replaces the form title in your reports."
      >
        <ToggleField
          id="track-all-forms"
          label="Track every form automatically"
          hint="Includes forms you add later. Turn this off to choose per form."
          checked={trackAll}
          onChange={(enabled) => {
            setTrackAll(enabled);
            setFormsDirty(true);
          }}
        />

        {!trackAll && (
          <div className="mt-5 overflow-hidden rounded-lg border border-border">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-left">
                <tr>
                  <th className="px-4 py-2.5 font-medium w-20">ID</th>
                  <th className="px-4 py-2.5 font-medium">Form</th>
                  <th className="px-4 py-2.5 font-medium w-64">Custom name</th>
                  <th className="px-4 py-2.5 font-medium w-32">Send</th>
                </tr>
              </thead>
              <tbody>
                {forms.length > 0 ? (
                  forms.map((form) => (
                    <tr key={`${activeTab}-${form.id}`} className="border-t border-border">
                      <td className="px-4 py-2.5 text-muted-foreground">{form.id}</td>
                      <td className="px-4 py-2.5">{form.title}</td>
                      <td className="px-4 py-2.5">
                        <Input
                          id={`custom-name-${form.id}`}
                          type="text"
                          placeholder={form.title}
                          value={form.customTitle}
                          onChange={(event) => {
                            setFormsDirty(true);
                            setForms(
                              forms.map((f) =>
                                f.id === form.id ? { ...f, customTitle: event.target.value } : f
                              )
                            );
                          }}
                        />
                      </td>
                      <td className="px-4 py-2.5">
                        <Switch
                          id={`send-to-leadtrackr-${form.id}`}
                          aria-label={`Send ${form.title} to LeadTrackr`}
                          checked={Boolean(form.sendToLeadTrackr)}
                          onCheckedChange={(checked) => {
                            setFormsDirty(true);
                            setForms(
                              forms.map((f) =>
                                f.id === form.id ? { ...f, sendToLeadTrackr: checked } : f
                              )
                            );
                          }}
                        />
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">
                      No forms found in {label} yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </Section>
    );
  })();

  return (
    <div className="mx-auto max-w-5xl py-8 pr-8">
      <header className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <img src={logoSvg} className="h-7" alt="LeadTrackr" />
          <span
            className={
              "inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm " +
              (connected
                ? "border-primary_branding/30 bg-primary_branding/10 text-primary_branding"
                : "border-border bg-muted text-muted-foreground")
            }
          >
            <span
              className={
                "h-2 w-2 rounded-full " + (connected ? "bg-primary_branding" : "bg-muted-foreground")
              }
              aria-hidden="true"
            />
            {connected
              ? `Connected · project ${projectId}`
              : hasProject
              ? "Incomplete · API token missing"
              : "Not connected"}
          </span>
        </div>
        <div className="flex items-center gap-5 text-sm">
          <ExternalLink href={links.dashboard}>Open dashboard</ExternalLink>
          <ExternalLink href={links.docs.wordpress}>Documentation</ExternalLink>
        </div>
      </header>

      {!connected && (
        <div className="mb-6 rounded-lg border border-border bg-muted/40 p-4 text-sm">
          <p className="font-medium">
            {hasProject
              ? "Add your API token to finish the setup"
              : "Add your Project ID and API token to start collecting leads"}
          </p>
          <p className="text-muted-foreground mt-1">
            {hasProject ? (
              <>
                Your project is set, but leads are still being sent without authentication.{" "}
                <ExternalLink href={links.docs.apiToken}>Where to find your token</ExternalLink>
              </>
            ) : (
              <>
                You will find both under Projects in your LeadTrackr dashboard. No leads are
                sent until the Project ID is filled in.{" "}
                <ExternalLink href={links.docs.createProject}>How to create a project</ExternalLink>
              </>
            )}
          </p>
        </div>
      )}

      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="leadbot">LeadBot</TabsTrigger>
          {BUILDERS.map((builder) => (
            <TabsTrigger
              key={builder.tab}
              value={builder.tab}
              disabled={!window.wpData[builder.key].enabled}
            >
              {builder.label}
            </TabsTrigger>
          ))}
          <TabsTrigger value="divi" disabled={!window.wpData.divi.enabled}>
            Divi
          </TabsTrigger>
        </TabsList>

        <div className="mt-4">
          <TabsContent value="general">
            <Section
              title="Connection"
              description="Where this site sends its leads."
            >
              <div className="space-y-5">
                <Field
                  label="Project ID"
                  htmlFor="project-id"
                  hint={
                    <>
                      Find it under Projects in your dashboard.{" "}
                      <ExternalLink href={links.projects}>Open Projects</ExternalLink>
                    </>
                  }
                >
                  <Input
                    id="project-id"
                    name="project-id"
                    type="text"
                    value={projectId}
                    onChange={(event) => setProjectId(event.target.value)}
                  />
                </Field>

                <Field
                  label="API Token"
                  htmlFor="api-token"
                  badge={apiTokenSet || !tokenRequired ? undefined : "Required"}
                  hint={
                    <>
                      Authenticates this site and sends leads server-side.{" "}
                      <ExternalLink href={links.docs.apiToken}>Where to find your token</ExternalLink>
                    </>
                  }
                >
                  <Input
                    id="api-token"
                    name="api-token"
                    type="password"
                    autoComplete="off"
                    value={apiToken}
                    placeholder={apiTokenSet ? "Saved — type a new token to replace it" : ""}
                    onChange={(event) => setApiToken(event.target.value)}
                  />
                </Field>
              </div>
            </Section>

            <Section
              title="Channel Flow"
              description={
                <>
                  Records the visitor's marketing journey — one entry per session, with the
                  source, campaign and landing page — and attaches it to every lead.{" "}
                  <ExternalLink href={links.docs.channelFlow}>How Channel Flow works</ExternalLink>
                </>
              }
            >
              <div className="space-y-5">
                <ToggleField
                  id="channelflow-enabled"
                  label="Enable Channel Flow tracking"
                  hint="See which channels every lead went through before converting, from the first visit to the form they filled in."
                  checked={channelFlowEnabled}
                  onChange={setChannelFlowEnabled}
                />

                {channelFlowEnabled && (
                  <>
                    <ToggleField
                      id="custom-utm"
                      label="Use custom UTM parameter names"
                      hint="Only needed if your ads use something other than utm_source and friends."
                      checked={showCustomUtm}
                      onChange={(checked) => {
                        setShowCustomUtm(checked);
                        if (!checked) setUtmParams(DEFAULT_UTM);
                      }}
                    />

                    {showCustomUtm && (
                      <div className="ml-7 space-y-4 border-l border-border pl-5">
                        {UTM_FIELDS.map(({ key, label }) => (
                          <Field key={key} label={label} htmlFor={`utm-${key}`}>
                            <Input
                              id={`utm-${key}`}
                              type="text"
                              value={utmParams[key]}
                              placeholder={DEFAULT_UTM[key]}
                              onChange={(event) =>
                                setUtmParams({ ...utmParams, [key]: event.target.value })
                              }
                            />
                          </Field>
                        ))}
                      </div>
                    )}
                  </>
                )}
              </div>
            </Section>

          </TabsContent>

          <TabsContent value="leadbot">
            <Section
              title="LeadBot"
              description={
                <>
                  A contact launcher in the corner of your site: message, phone and WhatsApp,
                  with every conversation landing in LeadTrackr with full attribution. The
                  script is only loaded when this is switched on.{" "}
                  <ExternalLink href={links.docs.wordpress}>Documentation</ExternalLink>
                </>
              }
            >
              <ToggleField
                id="leadbot-enabled"
                label="Enable the LeadBot on this site"
                hint={
                  hasProject
                    ? "Loads roughly 18 KB, after the page has rendered."
                    : "Fill in your Project ID first — the LeadBot needs it to send leads."
                }
                checked={leadbot.enabled}
                onChange={(checked) => setBot("enabled", checked)}
                disabled={!hasProject}
              />
            </Section>

            {leadbot.enabled && (
              <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
                <div>
                <Section
                  title="Who is talking"
                  description="Shown at the top of the panel. Naming an agent makes the LeadBot speak as “I” instead of “we”."
                >
                  <div className="space-y-5">
                    <Field label="Company name" htmlFor="lb-company">
                      <Input
                        id="lb-company"
                        type="text"
                        value={leadbot.companyName}
                        onChange={(e) => setBot("companyName", e.target.value)}
                      />
                    </Field>
                    <Field label="Agent name" htmlFor="lb-agent" badge="Optional">
                      <Input
                        id="lb-agent"
                        type="text"
                        value={leadbot.agentName}
                        onChange={(e) => setBot("agentName", e.target.value)}
                      />
                    </Field>
                    <Field
                      label="Agent photo"
                      htmlFor="lb-agent-photo"
                      badge="Optional"
                      hint="Full URL to a square image."
                    >
                      <Input
                        id="lb-agent-photo"
                        type="url"
                        value={leadbot.agentPhoto}
                        onChange={(e) => setBot("agentPhoto", e.target.value)}
                      />
                    </Field>
                    <Field
                      label="Greeting"
                      htmlFor="lb-greeting"
                      badge="Optional"
                      hint="Leave empty to use the built-in greeting for the visitor's language."
                    >
                      <Input
                        id="lb-greeting"
                        type="text"
                        value={leadbot.greeting}
                        onChange={(e) => setBot("greeting", e.target.value)}
                      />
                    </Field>
                    <Field
                      label="Response time"
                      htmlFor="lb-response-time"
                      badge="Optional"
                      hint="For example: Average response time: within 15 minutes."
                    >
                      <Input
                        id="lb-response-time"
                        type="text"
                        value={leadbot.responseTimeText}
                        onChange={(e) => setBot("responseTimeText", e.target.value)}
                      />
                    </Field>
                  </div>
                </Section>

                <Section
                  title="Channels"
                  description="A channel only appears when you fill in its details. Leave both empty for a message-only LeadBot."
                >
                  <div className="space-y-5">
                    <Field
                      label="Phone number"
                      htmlFor="lb-phone"
                      badge="Optional"
                      hint="Shown as a call button."
                    >
                      <Input
                        id="lb-phone"
                        type="tel"
                        value={leadbot.phone}
                        onChange={(e) => setBot("phone", e.target.value)}
                      />
                    </Field>
                    <Field
                      label="WhatsApp number"
                      htmlFor="lb-whatsapp"
                      badge="Optional"
                      hint="International format, for example +31612345678."
                    >
                      <Input
                        id="lb-whatsapp"
                        type="tel"
                        value={leadbot.whatsapp}
                        onChange={(e) => setBot("whatsapp", e.target.value)}
                      />
                    </Field>

                    <ToggleField
                      id="lb-call-tracking"
                      label="Activate YesWeTrack call tracking in the LeadBot"
                      hint="Shows the dynamically inserted YesWeTrack number instead of the one above, for both the displayed number and the call link. Only useful on sites that already run YesWeTrack call tracking."
                      checked={leadbot.callTracking}
                      onChange={(checked) => setBot("callTracking", checked)}
                    />
                    <ToggleField
                      id="lb-wa-interceptor"
                      label="Intercept existing WhatsApp links"
                      hint="Clicks on wa.me links already on your pages open the LeadBot first, so the conversation is captured as a lead."
                      checked={leadbot.whatsappInterceptor}
                      onChange={(checked) => setBot("whatsappInterceptor", checked)}
                    />
                    <ToggleField
                      id="lb-wa-phone-question"
                      label="Ask for a phone number in the WhatsApp flow"
                      hint="Turn off to send visitors straight through to WhatsApp. The lead still arrives, without a phone number."
                      checked={leadbot.whatsappPhoneQuestion}
                      onChange={(checked) => setBot("whatsappPhoneQuestion", checked)}
                    />
                  </div>
                </Section>

                <Section title="Appearance">
                  <div className="space-y-5">
                    <Field label="Corner" htmlFor="lb-position">
                      <select
                        id="lb-position"
                        className="w-full"
                        value={leadbot.position}
                        onChange={(e) => setBot("position", e.target.value as "right" | "left")}
                      >
                        <option value="right">Bottom right</option>
                        <option value="left">Bottom left</option>
                      </select>
                    </Field>

                    <div className="flex gap-4">
                      <Field label="Distance from bottom" htmlFor="lb-offset-bottom">
                        <Input
                          id="lb-offset-bottom"
                          type="number"
                          value={leadbot.offsetBottom}
                          onChange={(e) => setBot("offsetBottom", Number(e.target.value))}
                        />
                      </Field>
                      <Field label="Distance from the side" htmlFor="lb-offset-side">
                        <Input
                          id="lb-offset-side"
                          type="number"
                          value={leadbot.offsetSide}
                          onChange={(e) => setBot("offsetSide", Number(e.target.value))}
                        />
                      </Field>
                    </div>

                    <Field label="Language" htmlFor="lb-language">
                      <select
                        id="lb-language"
                        className="w-full"
                        value={leadbot.language}
                        onChange={(e) =>
                          setBot("language", e.target.value as "auto" | "nl" | "en")
                        }
                      >
                        <option value="auto">Follow the page language</option>
                        <option value="nl">Nederlands</option>
                        <option value="en">English</option>
                      </select>
                    </Field>

                    <Field
                      label="Brand colour"
                      htmlFor="lb-primary"
                      badge="Optional"
                      hint="Leave empty to keep the LeadTrackr green."
                    >
                      <div className="flex items-center gap-3">
                        <input
                          id="lb-primary"
                          type="color"
                          value={leadbot.themePrimary || "#52b483"}
                          onChange={(e) => setBot("themePrimary", e.target.value)}
                        />
                        <Input
                          type="text"
                          aria-label="Brand colour hex"
                          placeholder="#52b483"
                          value={leadbot.themePrimary}
                          onChange={(e) => setBot("themePrimary", e.target.value)}
                        />
                      </div>
                    </Field>

                    <Field
                      label="Hover colour"
                      htmlFor="lb-primary-hover"
                      badge="Optional"
                      hint="A slightly darker shade of your brand colour."
                    >
                      <div className="flex items-center gap-3">
                        <input
                          id="lb-primary-hover"
                          type="color"
                          value={leadbot.themePrimaryHover || "#3e8762"}
                          onChange={(e) => setBot("themePrimaryHover", e.target.value)}
                        />
                        <Input
                          type="text"
                          aria-label="Hover colour hex"
                          placeholder="#3e8762"
                          value={leadbot.themePrimaryHover}
                          onChange={(e) => setBot("themePrimaryHover", e.target.value)}
                        />
                      </div>
                    </Field>

                    <Field label="Corner rounding" htmlFor="lb-radius">
                      <Input
                        id="lb-radius"
                        type="number"
                        value={leadbot.themeRadius}
                        onChange={(e) => setBot("themeRadius", Number(e.target.value))}
                      />
                    </Field>

                    <ToggleField
                      id="lb-teaser"
                      label="Show the teaser bubble"
                      hint="The short message that appears next to the launcher."
                      checked={leadbot.teaser}
                      onChange={(checked) => setBot("teaser", checked)}
                    />
                    <ToggleField
                      id="lb-launcher"
                      label="Show the launcher button"
                      hint="Turn off to hide the button entirely and only use the WhatsApp interception above."
                      checked={leadbot.launcher}
                      onChange={(checked) => setBot("launcher", checked)}
                    />
                  </div>
                </Section>
                </div>

                <aside className="lg:sticky lg:top-8 self-start">
                  <Section
                    title="Live preview"
                    description="Your changes appear here a moment after you make them. Submissions in this preview are discarded — no lead is created."
                  >
                    <iframe
                      title="LeadBot preview"
                      srcDoc={previewDoc}
                      sandbox="allow-scripts allow-same-origin allow-popups"
                      className="h-[520px] w-full rounded-md border border-border bg-white"
                    />
                  </Section>
                </aside>
              </div>
            )}

          </TabsContent>

          {[...BUILDERS.map((b) => b.tab), "divi"].map((tab) => (
            <TabsContent key={tab} value={tab}>
              {formsContent}
            </TabsContent>
          ))}
        </div>
      </Tabs>

      {/* Sits above everything and only appears once something changed, so the
          page can never look finished while an edit is still unsaved. */}
      {dirty && (
        <div className="sticky bottom-4 z-20 mt-3 flex items-center justify-between gap-4 rounded-xl border bg-card px-5 py-3 shadow-lg">
          <p className="text-sm font-medium">You have unsaved changes</p>
          <div className="flex items-center gap-2">
            <Button variant="outline" disabled={loading} onClick={discardChanges}>
              Discard
            </Button>
            <Button
              disabled={loading}
              onClick={
                activeTab === "general"
                  ? onSaveGeneral
                  : activeTab === "leadbot"
                  ? onSaveLeadBot
                  : onSaveForms
              }
            >
              {loading ? "Saving…" : "Save changes"}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

export default App;
