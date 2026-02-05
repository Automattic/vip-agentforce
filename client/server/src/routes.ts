import { FastifyInstance } from "fastify";
import {
  messageSchema,
  typingSchema,
  messagesSchema,
  initializeSchema,
  endChatSchema,
  serverSentEventsSchema,
} from "./schema";
import {
  handleEndChat,
  handleGetMessages,
  handleInitialize,
  handleSendMessage,
  handleSSEConnection,
  handleTypingIndicator,
} from "./handlers";
import { MessageRequest, ServerSentEventRequest, TypingRequest } from "./types";

export default async function messagingRoutes(fastify: FastifyInstance) {
  if (
    !process.env.SALESFORCE_SCRT_URL ||
    !process.env.SALESFORCE_ORG_ID ||
    !process.env.SALESFORCE_DEVELOPER_NAME
  ) {
    throw new Error("Missing required environment variables");
  }

  const salesforceConfig = {
    scrtUrl: process.env.SALESFORCE_SCRT_URL,
    orgId: process.env.SALESFORCE_ORG_ID,
    esDeveloperName: process.env.SALESFORCE_DEVELOPER_NAME,
  };

  fastify.get(
    "/chat/initialize",
    {
      schema: initializeSchema,
    },
    () => handleInitialize(salesforceConfig)
  );

  fastify.post<MessageRequest>(
    "/chat/message",
    {
      schema: messageSchema,
    },
    async (request) => handleSendMessage(salesforceConfig, request)
  );

  fastify.post<TypingRequest>(
    "/chat/typing",
    {
      schema: typingSchema,
    },
    async (request) => handleTypingIndicator(salesforceConfig, request)
  );

  fastify.get<ServerSentEventRequest>(
    "/chat/sse",
    { schema: serverSentEventsSchema },
    async (request, reply) =>
      handleSSEConnection(salesforceConfig, request, reply)
  );

  fastify.get<MessageRequest>(
    "/chat/message",
    {
      schema: messagesSchema,
    },
    async (request) => handleGetMessages(salesforceConfig, request)
  );

  fastify.post<MessageRequest>(
    "/chat/end",
    {
      schema: endChatSchema,
    },
    async (request) => handleEndChat(salesforceConfig, request)
  );
}
