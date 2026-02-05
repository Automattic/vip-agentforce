import { FastifySchema } from "fastify";

export const commonSchemas = {
  headers: {
    type: "object",
    required: ["authorization", "x-conversation-id"],
    properties: {
      authorization: { type: "string", pattern: "^Bearer " },
      "x-conversation-id": { type: "string", format: "uuid" },
    },
  },
  successResponse: {
    type: "object",
    properties: {
      success: { type: "boolean" },
    },
  },
} as const;

// Route-specific schemas
export const messageSchema: FastifySchema = {
  headers: commonSchemas.headers,
  body: {
    type: "object",
    required: ["message"],
    properties: {
      message: { type: "string", minLength: 1 },
    },
  },
  response: {
    200: commonSchemas.successResponse,
  },
};

export const typingSchema: FastifySchema = {
  headers: commonSchemas.headers,
  body: {
    type: "object",
    required: ["isTyping"],
    properties: {
      isTyping: { type: "boolean" },
    },
  },
  response: {
    200: commonSchemas.successResponse,
  },
};

export const serverSentEventsSchema: FastifySchema = {
  querystring: {
    type: "object",
    properties: {
      token: { type: "string" },
    },
    required: ["token"],
  },
};

export const messagesSchema: FastifySchema = {
  headers: commonSchemas.headers,
  response: {
    200: {
      type: "object",
      properties: {
        entries: {
          type: "array",
          items: {
            type: "object",
            required: ["id", "content", "sender", "timestamp"],
            properties: {
              id: { type: "string" },
              content: { type: "string" },
              sender: {
                type: "object",
                required: ["role", "displayName"],
                properties: {
                  role: { type: "string" },
                  displayName: { type: "string" },
                },
              },
              timestamp: { type: "number" },
            },
          },
        },
      },
    },
  },
};

export const initializeSchema: FastifySchema = {
  response: {
    200: {
      type: "object",
      properties: {
        accessToken: { type: "string" },
        conversationId: { type: "string" },
      },
    },
  },
};

export const endChatSchema: FastifySchema = {
  headers: commonSchemas.headers,
  response: {
    200: commonSchemas.successResponse,
  },
};
