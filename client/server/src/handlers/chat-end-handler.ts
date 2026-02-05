import { FastifyRequest } from "fastify";
import { BaseMessageRequest, SalesforceConfig } from "../types";
import axios from "axios";

export async function handleEndChat(
  salesforceConfig: SalesforceConfig,
  request: FastifyRequest<BaseMessageRequest>
) {
  const token = request.headers.authorization.split(" ")[1];
  const conversationId = request.headers["x-conversation-id"];

  await axios.delete(
    `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/conversation/${conversationId}?esDeveloperName=${salesforceConfig.esDeveloperName}`,
    {
      headers: { Authorization: `Bearer ${token}` },
    }
  );

  return { success: true };
}
