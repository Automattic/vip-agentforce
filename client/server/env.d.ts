declare global {
  namespace NodeJS {
    interface ProcessEnv {
      SALESFORCE_SCRT_URL: string;
      SALESFORCE_ORG_ID: string;
      SALESFORCE_DEVELOPER_NAME: string;
    }
  }
}

export {};