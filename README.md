# Rinha de Backend 2025

Este projeto utiliza **PHP 8.4 com Swoole** para entregar alta performance em um ambiente de concorrência.

A solução é baseada em:

- **Swoole**: extensão para PHP com programação assíncrona e alta performance.
- **Redis**: fila de mensagens e armazenamento intermediário.
- **Docker**: ambiente isolado e reprodutível.
- **HAProxy**: proxy reverso leve e eficiente para balanceamento de carga.

## Limites de CPU e Memória por Serviço (Docker)

| Serviço         | CPU      | Memória (MB) |
|-----------------|----------|--------------|
| api01           | 0.35     | 90           |
| api02           | 0.35     | 90           |
| worker-payments | 0.20     | 70           |
| worker-health   | 0.15     | 20           |
| haproxy         | 0.30     | 50           |
| redis           | 0.15     | 30           |
| **Total**       | **1.50** | **350**      |

## Instruções de uso

> Para rodar o ambiente completo, basta usar o `make`:

```bash
make up