/**
 * Every outbound link in one place, so a moved documentation page is a single
 * edit instead of a hunt through the components.
 */
const APP = "https://app.leadtrackr.io";
const DOCS = "https://leadtrackr.io/docs";

export const links = {
  dashboard: APP,
  projects: `${APP}/dashboard/projects`,

  docs: {
    wordpress: `${DOCS}/lead-sources/wordpress-forms`,
    createProject: `${DOCS}/getting-started/create-account-and-project`,
    apiToken: `${DOCS}/api-reference/authentication`,
    channelFlow: `${DOCS}/lead-sources/channel-flow-tracker`,
    conversionLabels: `${DOCS}/conversion-labels/set-up-conversion-labels`,
  },
} as const;
