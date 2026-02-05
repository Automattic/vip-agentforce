import axios from "axios";
import { SalesforceConfig } from "../types";

export async function handleInitialize(salesforceConfig: SalesforceConfig) {
  const tokenResponse = await axios.post(
    `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/authorization/unauthenticated/access-token`,
    {
      orgId: salesforceConfig.orgId,
      esDeveloperName: salesforceConfig.esDeveloperName,
      capabilitiesVersion: "1",
      platform: "Web",
      context: {
        appName: "DemoMessagingClient",
        clientVersion: "1.0.0",
      },
    }
  );

  const accessToken = tokenResponse.data.accessToken;
  const lastEventId = tokenResponse.data.lastEventId;
  const conversationId = crypto.randomUUID().toLowerCase();

  await axios.post(
    `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/conversation`,
    {
      conversationId,
      esDeveloperName: salesforceConfig.esDeveloperName,
    },
    {
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
    }
  );

  return { accessToken, conversationId, lastEventId };
}
