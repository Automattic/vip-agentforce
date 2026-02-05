import { FastifyRequest } from "fastify";
import { SalesforceConfig, TypingRequest } from "../types";
import axios from "axios";

export async function handleTypingIndicator(
  salesforceConfig: SalesforceConfig,
  request: FastifyRequest<TypingRequest>
) {
  async () => {
    const token = request.headers.authorization.split(" ")[1];
    const conversationId = request.headers["x-conversation-id"];
    const isTyping = request.body.isTyping;

    await axios.post(
      `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/conversation/${conversationId}/entry`,
      {
        entryType: isTyping
          ? "TypingStartedIndicator"
          : "TypingStoppedIndicator",
        id: crypto.randomUUID(),
      },
      {
        headers: { Authorization: `Bearer ${token}` },
      }
    );
    return { success: true };
  };
}
