import { FastifyRequest } from "fastify";
import { BaseMessageRequest, MessageRequest, SalesforceConfig } from "../types";
import axios from "axios";

export async function handleSendMessage(
  salesforceConfig: SalesforceConfig,
  request: FastifyRequest<MessageRequest>
) {
  const conversationId = request.headers["x-conversation-id"];
  const token = request.headers.authorization.split(" ")[1];

  await axios.post(
    `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/conversation/${conversationId}/message`,
    {
      message: {
        id: crypto.randomUUID(),
        messageType: "StaticContentMessage",
        staticContent: {
          formatType: "Text",
          text: request.body.message,
        },
      },
      esDeveloperName: salesforceConfig.esDeveloperName,
      isNewMessagingSession: false,
      language: "",
    },
    {
      headers: { Authorization: `Bearer ${token}` },
    }
  );
}

// We don't actually use this, but if you wanted to retrieve old conversation or existing ones,
// you could using this handler. Note: the most recent message is at the start of the array.
export async function handleGetMessages(
  salesforceConfig: SalesforceConfig,
  request: FastifyRequest<BaseMessageRequest>
) {
  const token = request.headers.authorization.split(" ")[1];
  if (!token) {
    throw new Error("Missing authentication token");
  }

  const conversationId = request.headers["x-conversation-id"];

  const response = await axios.get(
    `https://${salesforceConfig.scrtUrl}/iamessage/api/v2/conversation/${conversationId}/entries`,
    {
      headers: { Authorization: `Bearer ${token}` },
      params: {
        limit: 50,
        direction: "FromEnd",
      },
    }
  );

  const unsortedEntries = parseMessageEntries(
    response.data.conversationEntries
  );

  const sortedEntries = unsortedEntries.sort(
    (a, b) => a.timestamp - b.timestamp
  );

  const entries = sortedEntries.map((entry) => ({
    id: entry.id,
    content: entry.content,
    sender: {
      role: entry.sender.role === "endUser" ? "user" : "ai",
      displayName: "",
    },
    timestamp: new Date(entry.timestamp),
  }));

  return { entries };
}

interface ParsedMessageEntry {
  id: string;
  content: string;
  sender: {
    role: "endUser" | "agent" | "system";
    displayName: string;
  };
  timestamp: number;
}

function parseMessageEntries(entries: any[]): ParsedMessageEntry[] {
  const filteredEntries = entries.filter((entry) => {
    return (
      entry.entryType === "Message" &&
      entry.entryPayload?.abstractMessage?.staticContent?.text
    );
  });

  const mappedEntries = filteredEntries.map((entry) => {
    const result = {
      id: entry.entryPayload.abstractMessage.id,
      content: entry.entryPayload.abstractMessage.staticContent.text,
      sender: {
        role: mapSenderRole(entry.sender.role),
        displayName: entry.senderDisplayName || "Unknown",
      },
      timestamp: entry.clientTimestamp,
    };
    return result;
  }) as ParsedMessageEntry[];

  return mappedEntries;
}

function mapSenderRole(role: string): string {
  const normalizedRole = role.toLowerCase();
  if (normalizedRole === "chatbot") return "agent";
  if (normalizedRole === "enduser") return "endUser";
  return "unknown";
}
